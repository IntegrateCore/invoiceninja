<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\Document;
use App\Services\IntegrateCore\ClientFiles;
use App\Services\IntegrateCore\FileLibrary;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as Config;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class IntegrateCoreLibrarySafetyTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        Container::setInstance($this->container);
        Facade::setFacadeApplication($this->container);
        Facade::clearResolvedInstances();
        $this->container->instance('config', new Config([
            'integratecore' => ['enabled' => true, 'url' => 'https://library.test', 'library_url' => 'https://library.test/files/', 'username' => 'test', 'password' => 'test'],
            'ninja' => ['hash_salt' => 'test'],
        ]));
        $this->container->instance('cache', new Repository(new ArrayStore()));
        $translator = new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader(), 'en');
        $this->container->instance('validator', new \Illuminate\Validation\Factory($translator, $this->container));
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
    }

    public function test_incomplete_listing_cannot_be_interpreted_as_empty(): void
    {
        $library = $this->listingLibrary(['isDir' => true]);
        $this->expectException(\RuntimeException::class);
        $library->entries('Client');
    }

    public function test_only_visible_single_segment_non_symlink_entries_are_returned(): void
    {
        $items = [];
        foreach (['report.pdf', '.secret.pdf', '../Other.pdf', 'nested/name.pdf', 'link.pdf'] as $name) {
            $items[] = ['name' => $name, 'isDir' => false, 'size' => 3, 'isSymlink' => $name === 'link.pdf'];
        }
        self::assertSame(['report.pdf'], array_column($this->listingLibrary(['isDir' => true, 'items' => $items])->entries('Client'), 'name'));
    }

    public function test_total_recursive_entries_are_bounded_even_after_directories_are_consumed(): void
    {
        $library = new class extends FileLibrary {
            public int $directories = 0;
            public function entries(string $folder = ''): array
            {
                if ($folder === 'Client') {
                    return array_map(fn ($i) => ['name' => (string) $i, 'isDir' => true], range(1, 6000));
                }
                ++$this->directories;
                return [['name' => 'file.txt', 'isDir' => false, 'size' => 1]];
            }
        };
        try {
            $library->files('Client');
            self::fail('The cumulative entry limit must stop traversal.');
        } catch (\RuntimeException $e) {
            self::assertSame(4001, $library->directories);
        }
    }

    public function test_temporary_download_enforces_actual_bytes_and_cleans_failure(): void
    {
        $before = glob(sys_get_temp_dir() . '/integratecore-*');
        $library = new class extends FileLibrary {
            public function read(string $path): ResponseInterface { return new Response(200, [], 'larger than declared'); }
        };
        try {
            $library->temporaryPath('Client/file.txt', 3);
            self::fail('The actual byte limit must be enforced.');
        } catch (\RuntimeException $e) {
            self::assertSame($before, glob(sys_get_temp_dir() . '/integratecore-*'));
        }
    }

    public function test_expired_token_is_refreshed_and_upload_body_rewound(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(200, [], 'old-token'), new Response(401),
            new Response(200, [], 'new-token'), new Response(200),
        ]));
        $handler->push(Middleware::history($history));
        $library = new FileLibrary(new HttpClient(['handler' => $handler]));
        $body = fopen('php://temp', 'w+');
        fwrite($body, 'private bytes');
        rewind($body);
        $library->upload('Client/private.txt', $body);
        self::assertCount(4, $history);
        self::assertSame('new-token', $history[3]['request']->getHeaderLine('X-Auth'));
        self::assertSame('private bytes', (string) $history[3]['request']->getBody());
    }

    public function test_private_rename_stays_private_across_repeated_sync_and_restore(): void
    {
        $files = $this->databaseFiles();
        $client = $this->client();
        $document = $this->document('Client/private.txt', false);
        $files->library->paths = ['Client/renamed.txt'];
        $files->sync($client, true);
        self::assertTrue(Document::withTrashed()->findOrFail($document->id)->trashed());
        self::assertFalse(Document::where('url', 'Client/renamed.txt')->firstOrFail()->is_public);
        $files->library->paths[] = 'Client/later.txt';
        $files->sync($client, true);
        self::assertFalse(Document::where('url', 'Client/later.txt')->firstOrFail()->is_public);
        self::assertSame(1, $files->status($client)['privacy_review_count']);
        $files->library->paths[] = 'Client/private.txt';
        $files->sync($client, true);
        self::assertFalse(Document::findOrFail($document->id)->is_public);
    }

    public function test_new_external_files_are_shared_and_missing_public_indexes_removed(): void
    {
        $files = $this->databaseFiles();
        $document = $this->document('Client/deleted.txt', true);
        $files->library->paths = ['Client/new.txt'];
        $files->sync($this->client(), true);
        self::assertNull(Document::withTrashed()->find($document->id));
        self::assertTrue(Document::where('url', 'Client/new.txt')->firstOrFail()->is_public);
    }

    public function test_interrupted_migration_copy_is_never_imported_as_shared(): void
    {
        $files = $this->databaseFiles();
        $document = $this->document('original.txt', false, 'local');
        $this->container['db']->table('client_file_migrations')->insert([
            'document_id' => $document->id, 'client_id' => 1, 'library_path' => 'Client/copied.txt',
        ]);
        $files->library->paths = ['Client/copied.txt'];
        $files->sync($this->client(), true);
        self::assertSame(1, Document::count());
        self::assertSame('local', $document->fresh()->disk);
    }

    public function test_upload_timeout_retains_private_record_before_bytes_are_sent(): void
    {
        $files = $this->databaseFiles();
        $files->library->uploadFailure = new \RuntimeException('Ambiguous network timeout');
        $temporary = tempnam(sys_get_temp_dir(), 'library-test-');
        file_put_contents($temporary, 'private bytes');
        try {
            $file = new \Illuminate\Http\UploadedFile($temporary, 'private.txt', 'text/plain', null, true);
            try {
                $files->upload($file, $this->client(), false);
                self::fail('The timeout must reach the caller.');
            } catch (\RuntimeException $e) {
                self::assertSame('Ambiguous network timeout', $e->getMessage());
            }
            self::assertTrue($files->library->recordExistedBeforeUpload);
            self::assertFalse(Document::where('url', 'Client/private.txt')->firstOrFail()->is_public);
            $files->library->paths = ['Client/private.txt'];
            $files->sync($this->client(), true);
            self::assertFalse(Document::where('url', 'Client/private.txt')->firstOrFail()->is_public);
        } finally {
            unlink($temporary);
        }
    }

    public function test_failed_migration_reserves_copy_and_retry_verifies_existing_bytes(): void
    {
        $files = $this->databaseFiles();
        $root = sys_get_temp_dir() . '/library-test-' . bin2hex(random_bytes(8));
        mkdir($root);
        file_put_contents($root . '/original.txt', 'private source bytes');
        $this->container['config']->set('filesystems.disks.local', ['driver' => 'local', 'root' => $root, 'throw' => true]);
        $this->container->instance('filesystem', new \Illuminate\Filesystem\FilesystemManager($this->container));
        $document = $this->document('original.txt', false, 'local');
        $files->library->checksumFailure = true;
        try {
            try {
                $files->migrate($this->client());
                self::fail('Failed verification must stop migration.');
            } catch (\RuntimeException $e) {
                self::assertSame('Checksum request failed', $e->getMessage());
            }
            $reservation = $this->container['db']->table('client_file_migrations')->first();
            self::assertNotNull($reservation);
            self::assertSame('local', $document->fresh()->disk);
            $files->library->paths = [$reservation->library_path];
            $files->sync($this->client(), true);
            self::assertSame(1, Document::count());
            $files->library->checksumFailure = false;
            $files->migrate($this->client());
            self::assertSame('integratecore', $document->fresh()->disk);
            self::assertFalse($document->fresh()->is_public);
            self::assertSame(1, $this->container['db']->table('client_file_migrations')->count());
            self::assertSame('private source bytes', file_get_contents($root . '/original.txt'));
        } finally {
            unlink($root . '/original.txt');
            rmdir($root);
        }
    }

    public function test_conflicting_upload_removes_only_new_record_and_never_deletes_remote_file(): void
    {
        $files = $this->databaseFiles();
        $files->library->uploadFailure = new \GuzzleHttp\Exception\ClientException('Conflict', new \GuzzleHttp\Psr7\Request('POST', 'https://library.test'), new Response(409));
        $temporary = tempnam(sys_get_temp_dir(), 'library-test-');
        file_put_contents($temporary, 'private bytes');
        try {
            try {
                $files->upload(new \Illuminate\Http\UploadedFile($temporary, 'existing.txt', 'text/plain', null, true), $this->client(), false);
                self::fail('The conflict must reject the upload.');
            } catch (\Illuminate\Validation\ValidationException $e) {
                self::assertSame(0, Document::withTrashed()->count());
                self::assertTrue($files->library->recordExistedBeforeUpload);
            }
        } finally {
            unlink($temporary);
        }
    }

    public function test_ambiguous_server_error_does_not_replay_upload(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([new Response(200, [], 'token'), new Response(500)]));
        $handler->push(Middleware::history($history));
        $library = new FileLibrary(new HttpClient(['handler' => $handler]));
        try {
            $library->upload('Client/private.txt', 'private bytes');
            self::fail('The remote server error must reach the caller.');
        } catch (\GuzzleHttp\Exception\ServerException $e) {
            self::assertCount(2, $history);
        }
    }

    private function listingLibrary(array $data): FileLibrary
    {
        return new class($data) extends FileLibrary {
            public function __construct(private array $data) {}
            public function request(string $method, string $endpoint, array $options = []): ResponseInterface
            {
                return new Response(200, [], json_encode($this->data));
            }
        };
    }

    private function client(): Client
    {
        $client = new Client();
        $client->id = 1;
        $client->company_id = 1;
        $client->user_id = 1;
        return $client;
    }

    private function document(string $path, bool $public, string $disk = 'integratecore'): Document
    {
        $document = new Document();
        $document->forceFill(['user_id' => 1, 'company_id' => 1, 'url' => $path, 'disk' => $disk,
            'name' => basename($path), 'size' => 3, 'type' => 'txt', 'hash' => bin2hex(random_bytes(32)),
            'is_public' => $public, 'documentable_type' => Client::class, 'documentable_id' => 1]);
        $document->save();
        return $document;
    }

    private function databaseFiles(): ClientFiles
    {
        $capsule = new Manager($this->container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $this->container->instance('db', $capsule->getDatabaseManager());
        $capsule->schema()->create('documents', function (Blueprint $table) {
            $table->increments('id');
            foreach (['user_id', 'company_id', 'documentable_id', 'size'] as $column) { $table->integer($column); }
            foreach (['url', 'disk', 'name', 'type', 'hash', 'documentable_type'] as $column) { $table->string($column); }
            $table->boolean('is_public'); $table->softDeletes(); $table->timestamps();
        });
        $capsule->schema()->create('client_file_folders', function (Blueprint $table) {
            $table->increments('id'); $table->integer('client_id'); $table->integer('company_id'); $table->string('folder'); $table->timestamps();
        });
        $capsule->schema()->create('client_file_migrations', function (Blueprint $table) {
            $table->increments('id'); $table->integer('document_id'); $table->integer('client_id'); $table->string('library_path');
            foreach (['original_disk', 'original_url', 'sha256'] as $column) { $table->string($column)->nullable(); }
            $table->timestamps(); $table->unique('document_id');
        });
        $capsule->table('client_file_folders')->insert(['client_id' => 1, 'company_id' => 1, 'folder' => 'Client']);
        $library = new class extends FileLibrary {
            public array $paths = [];
            public ?\Throwable $uploadFailure = null;
            public bool $recordExistedBeforeUpload = false;
            public bool $checksumFailure = false;
            public array $bytes = [];
            public function files(string $folder): array
            {
                return array_map(fn ($path) => ['library_path' => $path, 'size' => 3], $this->paths);
            }
            public function upload(string $path, $stream): void
            {
                $this->recordExistedBeforeUpload = Document::where('url', $path)->exists();
                if ($this->uploadFailure) { throw $this->uploadFailure; }
                if (isset($this->bytes[$path])) {
                    throw new \GuzzleHttp\Exception\ClientException('Conflict', new \GuzzleHttp\Psr7\Request('POST', 'https://library.test'), new Response(409));
                }
                $this->bytes[$path] = stream_get_contents($stream);
            }
            public function checksum(string $path): string
            {
                if ($this->checksumFailure) { throw new \RuntimeException('Checksum request failed'); }
                return hash('sha256', $this->bytes[$path]);
            }
            public function delete(string $path): void
            {
                throw new \LogicException('Upload errors must not delete remote files.');
            }
        };
        return new class($library) extends ClientFiles {
            public function __construct(public FileLibrary $library) { parent::__construct($library); }
            public function clientDocuments(Client $client)
            {
                return Document::where('company_id', $client->company_id)->where('documentable_type', Client::class)->where('documentable_id', $client->id);
            }
        };
    }
}
