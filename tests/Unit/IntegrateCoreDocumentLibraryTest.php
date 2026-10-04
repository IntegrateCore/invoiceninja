<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\ClientFileFolder;
use App\Models\Document;
use App\Services\IntegrateCore\ClientFiles;
use App\Services\IntegrateCore\DocumentLibrary;
use App\Services\IntegrateCore\FileLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class IntegrateCoreDocumentLibraryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('documents', function (Blueprint $table) {
            $table->id();$table->string('name');$table->string('url');$table->string('disk');
            $table->boolean('is_public');$table->integer('size');$table->string('hash');
            $table->integer('company_id');$table->nullableTimestamps();$table->softDeletes();
        });
        foreach ([
            ['name'=>'Project/report.txt','url'=>'Client/Project/report.txt','is_public'=>true],
            ['name'=>'Project/Plans/plan.pdf','url'=>'Client/Project/Plans/plan.pdf','is_public'=>true],
            ['name'=>'Secret/private.txt','url'=>'Client/Secret/private.txt','is_public'=>false],
            ['name'=>'.DS_Store','url'=>'Client/.DS_Store','is_public'=>true],
            ['name'=>'other.txt','url'=>'Other Client/other.txt','is_public'=>true],
        ] as $row) {
            DB::table('documents')->insert($row+['disk'=>'integratecore','size'=>12,'hash'=>bin2hex(random_bytes(12)),'company_id'=>1]);
        }
    }

    private function library(): array
    {
        $client = new Client();$client->id=7;$client->company_id=1;
        $mapping = new ClientFileFolder(['folder'=>'Client','company_id'=>1,'client_id'=>7]);
        $files = $this->createMock(ClientFiles::class);
        $files->method('mapping')->willReturn($mapping);
        $files->method('clientDocuments')->willReturnCallback(fn () => Document::query());
        return [new DocumentLibrary($files,$this->createMock(FileLibrary::class)), $client];
    }

    public function test_portal_shows_only_top_level_folders_with_public_visible_children(): void
    {
        [$library,$client]=$this->library();
        $root=$library->listing($client,'',true);
        self::assertSame(['Project'],array_column($root['entries'],'name'));
        self::assertTrue($root['entries'][0]['is_dir']);
        $child=$library->listing($client,'Project',true);
        self::assertSame(['Plans','report.txt'],array_column($child['entries'],'name'));
        self::assertSame('Project/Plans',$child['entries'][0]['path']);
    }

    public function test_admin_can_browse_private_folder_without_cross_client_or_hidden_files(): void
    {
        [$library,$client]=$this->library();
        $root=$library->listing($client,'',false);
        self::assertSame(['Project','Secret'],array_column($root['entries'],'name'));
        $private=$library->listing($client,'Secret',false);
        self::assertFalse($private['entries'][0]['is_public']);
        self::assertSame([], $library->listing($client,'Secret',true)['entries']);
    }

    public function test_library_rejects_hidden_and_traversal_paths(): void
    {
        [$library,$client]=$this->library();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $library->listing($client,'Project/../Secret',false);
    }
}
