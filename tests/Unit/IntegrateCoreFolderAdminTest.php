<?php

namespace Tests\Unit;

use App\Http\Controllers\IntegrateCore\ClientFilesController;
use App\Http\Requests\Client\ShowClientRequest;
use App\Models\Client;
use App\Models\User;
use App\Services\IntegrateCore\ClientFiles;
use App\Services\IntegrateCore\DocumentLibrary;
use App\Services\IntegrateCore\FileLibrary;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IntegrateCoreFolderAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'integratecore.enabled' => true]);
        DB::purge('sqlite');
        Schema::create('clients', function (Blueprint $table) {
            $table->id(); $table->integer('company_id'); $table->string('name'); $table->softDeletes();
        });
        Schema::create('client_contacts', function (Blueprint $table) {
            $table->id(); $table->integer('client_id'); $table->string('email');
            $table->string('first_name'); $table->string('last_name'); $table->softDeletes();
        });
        Schema::create('client_file_folders', function (Blueprint $table) {
            $table->id(); $table->integer('client_id'); $table->integer('company_id'); $table->string('folder');
        });
        Schema::create('client_file_folder_tenants', function (Blueprint $table) {
            $table->string('folder'); $table->integer('company_id');
        });
        DB::table('clients')->insert([
            ['id' => 7, 'company_id' => 1, 'name' => 'Current Owner'],
            ['id' => 8, 'company_id' => 2, 'name' => 'Foreign Owner'],
        ]);
        DB::table('client_file_folders')->insert([
            ['client_id' => 7, 'company_id' => 1, 'folder' => 'Owned Folder'],
            ['client_id' => 8, 'company_id' => 2, 'folder' => 'Other Company Folder'],
        ]);
    }

    public function test_global_catalog_lists_unassigned_and_owned_folders_without_foreign_client_identity(): void
    {
        $library = $this->createMock(FileLibrary::class);
        $library->method('entries')->willReturn([
            ['name' => 'Unassigned Folder', 'isDir' => true], ['name' => 'Owned Folder', 'isDir' => true],
            ['name' => 'Other Company Folder', 'isDir' => true], ['name' => 'root-file.txt', 'isDir' => false],
        ]);
        $response = (new ClientFilesController())->allFolders($this->request(true), $library);
        $data = $response->getData(true);
        self::assertTrue($data['enabled']);
        self::assertSame(['Other Company Folder', 'Owned Folder', 'Unassigned Folder'], array_column($data['data'], 'folder'));
        self::assertSame(['folder' => 'Other Company Folder', 'assigned' => true, 'client_id' => null,
            'client_name' => null, 'assigned_to_other_company' => true], $data['data'][0]);
        self::assertSame((new Client())->encodePrimaryKey(7), $data['data'][1]['client_id']);
        self::assertSame('Current Owner', $data['data'][1]['client_name']);
        self::assertFalse($data['data'][2]['assigned']);
        self::assertStringNotContainsString('Foreign Owner', $response->getContent());
    }

    public function test_disabled_catalog_never_contacts_file_server(): void
    {
        config(['integratecore.enabled' => false]);
        $library = $this->createMock(FileLibrary::class);
        $library->expects(self::never())->method('entries');
        self::assertSame(['enabled' => false, 'data' => []], (new ClientFilesController())->allFolders($this->request(true), $library)->getData(true));
    }

    public function test_non_admin_is_blocked_before_catalog_picker_or_mutations(): void
    {
        $controller = new ClientFilesController();
        $request = $this->request(false, true);
        $client = new Client(); $client->id = 7; $client->company_id = 1;
        $library = $this->createMock(FileLibrary::class);
        $library->expects(self::never())->method('entries');
        $files = $this->createMock(ClientFiles::class);
        $files->expects(self::never())->method('assign');
        $files->expects(self::never())->method('migrate');
        foreach ([
            fn () => $controller->allFolders($request, $library), fn () => $controller->folders($request, $client, $library),
            fn () => $controller->update($request, $client, $files), fn () => $controller->refresh($request, $client, $files),
            fn () => $controller->updateFolder($request, $files, $library),
        ] as $operation) {
            try { $operation(); self::fail('Only an Invoice Ninja admin may manage folders.'); }
            catch (HttpException $e) { self::assertSame(403, $e->getStatusCode()); }
        }
    }

    public function test_admin_from_another_company_cannot_mutate_the_client_mapping(): void
    {
        $client = new Client(); $client->id = 8; $client->company_id = 2;
        $files = $this->createMock(ClientFiles::class);
        $files->expects(self::never())->method('assign');
        try { (new ClientFilesController())->update($this->request(true, true), $client, $files); self::fail('Admin rights are scoped to the active company.'); }
        catch (HttpException $e) { self::assertSame(403, $e->getStatusCode()); }
    }

    public function test_non_admin_read_only_library_browse_keeps_existing_permissions(): void
    {
        $client = new Client(); $client->id = 7; $client->company_id = 1;
        $request = $this->request(false, true);
        $request->merge(['path' => 'Project']);
        $library = $this->createMock(DocumentLibrary::class);
        $library->expects(self::once())->method('listing')->with($client, 'Project', false)->willReturn(['entries' => []]);
        self::assertSame(['data' => ['entries' => []]], (new ClientFilesController())->browse($request, $client, $library)->getData(true));
    }

    public function test_central_assignment_decodes_client_in_active_company_and_returns_catalog(): void
    {
        $request = $this->request(true);
        $request->merge(['folder' => 'Owned Folder', 'client_id' => (new Client())->encodePrimaryKey(7)]);
        $files = $this->createMock(ClientFiles::class);
        $files->expects(self::once())->method('changeFolder')->with(1, 'Owned Folder', self::callback(fn ($client) => $client->id === 7 && $client->company_id === 1));
        $files->expects(self::once())->method('sync')->with(self::callback(fn ($client) => $client->id === 7), true);
        $library = $this->createMock(FileLibrary::class); $library->method('entries')->willReturn([]);
        self::assertSame(['enabled' => true, 'data' => []], (new ClientFilesController())->updateFolder($request, $files, $library)->getData(true));
    }

    public function test_central_unassignment_passes_null_and_never_syncs_former_owner(): void
    {
        $request = $this->request(true); $request->merge(['folder' => 'Owned Folder', 'client_id' => null]);
        $files = $this->createMock(ClientFiles::class);
        $files->expects(self::once())->method('changeFolder')->with(1, 'Owned Folder', null); $files->expects(self::never())->method('sync');
        $library = $this->createMock(FileLibrary::class); $library->method('entries')->willReturn([]);
        (new ClientFilesController())->updateFolder($request, $files, $library);
    }

    public function test_central_assignment_rejects_foreign_company_client_before_service_mutation(): void
    {
        $request = $this->request(true); $request->merge(['folder' => 'Owned Folder', 'client_id' => (new Client())->encodePrimaryKey(8)]);
        $files = $this->createMock(ClientFiles::class); $files->expects(self::never())->method('changeFolder');
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        (new ClientFilesController())->updateFolder($request, $files, $this->createMock(FileLibrary::class));
    }

    public function test_catalog_marks_unassigned_foreign_tenant_folder_unavailable_without_identity(): void
    {
        DB::table('client_file_folder_tenants')->insert(['folder' => 'Unassigned Foreign', 'company_id' => 2]);
        $library = $this->createMock(FileLibrary::class); $library->method('entries')->willReturn([['name' => 'Unassigned Foreign', 'isDir' => true]]);
        self::assertSame(['folder' => 'Unassigned Foreign', 'assigned' => false, 'client_id' => null, 'client_name' => null, 'assigned_to_other_company' => true],
            (new ClientFilesController())->allFolders($this->request(true), $library)->getData(true)['data'][0]);
    }

    public function test_per_client_picker_excludes_unassigned_foreign_tenants(): void
    {
        DB::table('client_file_folder_tenants')->insert(['folder' => 'Unassigned Foreign', 'company_id' => 2]);
        \Illuminate\Support\Facades\Gate::shouldReceive('authorize')->once()->with('edit', self::isInstanceOf(Client::class));
        $client = new Client(); $client->id = 7; $client->company_id = 1;
        $library = $this->createMock(FileLibrary::class);
        $library->method('entries')->willReturn(array_map(fn ($name) => ['name' => $name, 'isDir' => true],
            ['Owned Folder', 'Unassigned Foreign', 'Other Company Folder', 'Available']));
        self::assertSame(['data' => ['Available', 'Owned Folder']],
            (new ClientFilesController())->folders($this->request(true, true), $client, $library)->getData(true));
    }

    private function request(bool $admin, bool $clientRequest = false): Request
    {
        $user = $this->createMock(User::class);
        $user->method('isAdmin')->willReturn($admin);
        $user->method('companyId')->willReturn(1);
        $request = $clientRequest ? new ShowClientRequest() : new Request();
        $request->setUserResolver(fn () => $user);
        return $request;
    }
}
