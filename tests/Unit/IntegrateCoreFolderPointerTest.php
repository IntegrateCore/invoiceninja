<?php

namespace Tests\Unit;

use App\Models\{Client, ClientFileFolder, Document, Invoice};
use App\Services\IntegrateCore\{ClientFiles, DocumentLibrary, FileLibrary};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Cache, DB, Schema};
use Tests\TestCase;

class IntegrateCoreFolderPointerTest extends TestCase
{
    private ClientFiles $files;
    private FileLibrary $storage;
    private Client $a;
    private Client $b;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'integratecore.enabled' => true]);
        DB::purge('sqlite'); Cache::flush();
        Schema::create('clients', function (Blueprint $t) {
            $t->id(); $t->integer('company_id'); $t->integer('user_id'); $t->string('name'); $t->softDeletes();
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id(); $t->integer('company_id'); $t->integer('client_id'); $t->softDeletes();
        });
        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            foreach (['company_id', 'user_id', 'documentable_id', 'size'] as $c) { $t->integer($c); }
            foreach (['url', 'disk', 'name', 'type', 'hash', 'documentable_type'] as $c) { $t->string($c); }
            $t->boolean('is_public'); $t->softDeletes(); $t->timestamps();
        });
        Schema::create('client_file_folders', function (Blueprint $t) {
            $t->id(); $t->integer('company_id'); $t->integer('client_id')->unique(); $t->string('folder')->unique(); $t->timestamps();
        });
        Schema::create('client_file_migrations', function (Blueprint $t) {
            $t->id(); $t->integer('document_id')->unique(); $t->integer('client_id'); $t->string('library_path'); $t->timestamps();
        });
        (require base_path('database/migrations/2026_10_04_220000_create_client_file_document_references.php'))->up();
        DB::table('clients')->insert([
            ['id' => 1, 'company_id' => 1, 'user_id' => 1, 'name' => 'A'],
            ['id' => 2, 'company_id' => 1, 'user_id' => 1, 'name' => 'B'],
        ]);
        DB::table('invoices')->insert(['id' => 10, 'company_id' => 1, 'client_id' => 1]);
        $this->a = Client::withoutEagerLoads()->find(1); $this->b = Client::withoutEagerLoads()->find(2);
        ClientFileFolder::create(['company_id' => 1, 'client_id' => 1, 'folder' => 'F']);
        ClientFileFolder::create(['company_id' => 1, 'client_id' => 2, 'folder' => 'G']);
        $this->storage = new class extends FileLibrary {
            public array $paths = [];
            public function entries(string $folder = ''): array { return array_map(fn ($name) => ['name' => $name, 'isDir' => true], ['F', 'G', 'H']); }
            public function files(string $folder): array { return array_map(fn ($path) => ['library_path' => $path, 'size' => 3], array_values(array_filter($this->paths, fn ($path) => FileLibrary::inside($path, $folder)))); }
            public function upload(string $path, $stream): void { throw new \LogicException('Pointer changes must never copy files.'); }
            public function delete(string $path): void { throw new \LogicException('Pointer changes must never delete files.'); }
        };
        $this->files = new class($this->storage) extends ClientFiles {
            public function clientDocuments(Client $client) {
                return Document::where('company_id', $client->company_id)->where(function ($q) use ($client) {
                    $q->where(fn ($q) => $q->where('documentable_type', Client::class)->where('documentable_id', $client->id))
                        ->orWhere(fn ($q) => $q->where('documentable_type', Invoice::class)->whereIn('documentable_id', DB::table('invoices')->where('client_id', $client->id)->pluck('id')));
                });
            }
        };
    }

    private function document(string $url, bool $public, int $owner = 1, string $type = Client::class): Document
    {
        $d = new Document();
        $d->forceFill(['company_id' => 1, 'user_id' => 1, 'url' => $url, 'disk' => 'integratecore', 'name' => basename($url), 'type' => 'txt', 'size' => 3,
            'hash' => bin2hex(random_bytes(32)), 'is_public' => $public, 'documentable_type' => $type, 'documentable_id' => $owner]);
        $d->save(); return $d;
    }

    public function test_transfer_changes_only_metadata_and_preserves_direct_ids_hashes_and_privacy(): void
    {
        $private = $this->document('F/private.txt', false); $public = $this->document('F/public.txt', true);
        $previous = $this->document('G/previous.txt', false, 2);
        $this->storage->paths = ['F/private.txt', 'F/public.txt', 'G/previous.txt'];
        $this->files->assign($this->b, 'F');
        self::assertNull($this->files->mapping($this->a)); self::assertSame('F', $this->files->mapping($this->b)->folder);
        foreach ([$private, $public] as $original) {
            $current = Document::findOrFail($original->id);
            self::assertSame(2, $current->documentable_id); self::assertSame($original->hash, $current->hash);
            self::assertSame($original->is_public, $current->is_public); self::assertFalse($current->trashed());
        }
        self::assertTrue(Document::withTrashed()->find($previous->id)->trashed());
        self::assertSame(2, $this->files->status($this->b)['document_count']);
        self::assertSame(0, $this->files->status($this->b)['privacy_review_count']);
        self::assertSame(['F/private.txt', 'F/public.txt', 'G/previous.txt'], $this->storage->paths);
    }

    public function test_unassignment_and_reassignment_reuse_private_metadata_and_revoke_direct_access(): void
    {
        $d = $this->document('F/private.txt', false); $this->storage->paths = ['F/private.txt'];
        $this->files->changeFolder(1, 'F', null);
        self::assertTrue(Document::withTrashed()->find($d->id)->trashed());
        try { $this->files->path($d); self::fail('A former direct index cannot access an unassigned folder.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { self::assertSame(404, $e->getStatusCode()); }
        $this->files->assign($this->a, 'F');
        $current = Document::findOrFail($d->id);
        self::assertSame($d->hash, $current->hash); self::assertFalse($current->is_public); self::assertSame(1, Document::count());
    }

    public function test_entity_relationship_and_exact_path_survive_pointer_change_with_private_new_owner_index(): void
    {
        $original = $this->document('F/invoice.txt', false, 10, Invoice::class); $this->storage->paths = ['F/invoice.txt'];
        $this->files->assign($this->b, 'F');
        $current = Document::findOrFail($original->id);
        self::assertSame(Invoice::class, $current->documentable_type); self::assertSame(10, $current->documentable_id);
        self::assertSame($original->hash, $current->hash); self::assertSame('F/invoice.txt', $this->files->path($current));
        $index = Document::where('documentable_type', Client::class)->firstOrFail();
        self::assertSame(2, $index->documentable_id); self::assertFalse($index->is_public); self::assertNotSame($original->id, $index->id);
        $this->files->assign($this->a, 'H');
        self::assertNotNull(Document::find($original->id)); self::assertSame('F/invoice.txt', $this->files->path($current));
        self::assertSame(1, DB::table('client_file_document_references')->count());
        self::assertSame([], (new DocumentLibrary($this->files, $this->storage))->listing($this->a, '', false)['entries']);
        $current->url = 'F/another.txt';
        try { $this->files->path($current); self::fail('Retained references must match the exact immutable path.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { self::assertSame(404, $e->getStatusCode()); }
        $current->url = 'H/another.txt';
        try { $this->files->path($current); self::fail('The current view must not override a registered exact attachment path.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { self::assertSame(404, $e->getStatusCode()); }
    }

    public function test_private_original_wins_over_public_alias_for_listing_and_sync(): void
    {
        $original = $this->document('F/invoice.txt', false, 10, Invoice::class);
        $alias = $this->document('F/invoice.txt', true); $this->storage->paths = ['F/invoice.txt'];
        $library = new DocumentLibrary($this->files, $this->storage);
        self::assertCount(1, $library->documents($this->a, '', false));
        self::assertSame($original->id, $library->documents($this->a, '', false)->first()->id);
        self::assertCount(0, $library->documents($this->a, '', true));
        $this->files->sync($this->a, true);
        self::assertFalse(Document::find($alias->id)->is_public);
    }

    public function test_interrupted_old_owner_migration_reservation_stays_global_after_transfer(): void
    {
        $original = $this->document('original.txt', false); $original->disk = 'local'; $original->save();
        DB::table('client_file_migrations')->insert(['document_id' => $original->id, 'client_id' => 1, 'library_path' => 'F/reserved.txt']);
        $this->storage->paths = ['F/reserved.txt']; $this->files->assign($this->b, 'F');
        self::assertFalse(Document::where('url', 'F/reserved.txt')->exists());
        self::assertSame('local', Document::find($original->id)->disk);
    }

    public function test_foreign_company_or_invalid_folder_cannot_change_existing_mappings(): void
    {
        ClientFileFolder::where('folder', 'F')->update(['company_id' => 2]);
        foreach (['F', '../F', 'Missing'] as $folder) {
            try { $this->files->changeFolder(1, $folder, $this->b); self::fail('Unsafe assignment must be rejected.'); }
            catch (\Illuminate\Validation\ValidationException $e) { self::assertArrayHasKey('folder', $e->errors()); }
        }
        self::assertSame('G', $this->files->mapping($this->b)->folder); self::assertSame(2, ClientFileFolder::count());
    }

    public function test_unassigned_folder_retains_company_tenancy_even_without_remaining_documents(): void
    {
        $this->files->changeFolder(1, 'F', null);
        $foreign = new Client(); $foreign->id = 3; $foreign->company_id = 2;
        try { $this->files->changeFolder(2, 'F', $foreign); self::fail('Unassignment must not release tenant ownership.'); }
        catch (\Illuminate\Validation\ValidationException $e) { self::assertArrayHasKey('folder', $e->errors()); }
        self::assertSame(1, DB::table('client_file_folder_tenants')->where('folder', 'F')->value('company_id'));
        self::assertFalse(ClientFileFolder::where('folder', 'F')->exists());
    }

    public function test_old_folder_tombstones_and_entities_do_not_quarantine_or_delete_current_view(): void
    {
        $original = $this->document('F/invoice.txt', false, 10, Invoice::class);
        $tombstone = $this->document('F/missing.txt', false); $tombstone->delete();
        $this->storage->paths = ['H/new.txt']; $this->files->assign($this->a, 'H');
        self::assertTrue(Document::where('url', 'H/new.txt')->firstOrFail()->is_public);
        self::assertFalse(Document::findOrFail($original->id)->trashed());
        self::assertTrue(Document::withTrashed()->findOrFail($tombstone->id)->trashed());
        self::assertSame(0, $this->files->status($this->a)['privacy_review_count']);
    }

    public function test_public_direct_alias_is_denied_before_sync_when_private_original_exists(): void
    {
        Schema::create('client_contacts', function (Blueprint $t) {
            $t->id(); $t->integer('company_id'); $t->integer('client_id'); $t->string('email'); $t->softDeletes();
        });
        DB::table('client_contacts')->insert(['id' => 1, 'company_id' => 1, 'client_id' => 1, 'email' => 'viewer@example.test']);
        $contact = new \App\Models\ClientContact();
        $contact->forceFill(['id' => 1, 'company_id' => 1, 'client_id' => 1, 'email' => 'viewer@example.test']);
        auth()->guard('contact')->setUser($contact);
        $alias = $this->document('F/invoice.txt', true);
        $request = new \App\Http\Requests\ClientPortal\Documents\ShowDocumentRequest(); $request->merge(['document' => $alias]);
        self::assertTrue($request->authorize());
        $this->document('F/invoice.txt', false, 10, Invoice::class);
        self::assertFalse($request->authorize());
    }
}
