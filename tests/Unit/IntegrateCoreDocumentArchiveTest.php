<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\ClientFileFolder;
use App\Models\Document;
use App\Services\IntegrateCore\ClientFiles;
use App\Services\IntegrateCore\DocumentLibrary;
use App\Services\IntegrateCore\FileLibrary;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IntegrateCoreDocumentArchiveTest extends TestCase
{
    private array $content = [];
    private array $temporary = [];
    private array $archives = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            foreach (['name', 'url', 'disk', 'hash'] as $column) { $table->string($column); }
            $table->boolean('is_public');
            $table->integer('size');
            $table->integer('company_id');
            $table->nullableTimestamps();
            $table->softDeletes();
        });
        $this->addDocument('Client/Project/report.txt', 'report bytes');
        $this->addDocument('Client/Project/Plans/plan.pdf', 'nested plan bytes');
        $this->addDocument('Client/Secret/private.txt', 'private bytes', false);
        $this->addDocument('Client/.DS_Store', 'hidden bytes');
        $this->addDocument('Client/Project/.cache/secret.txt', 'hidden nested bytes');
        $this->addDocument('Other Client/other.txt', 'other client bytes');
        $this->addDocument('ClientTwo/sibling.txt', 'similar prefix bytes');
        $this->addDocument('Client/local.txt', 'old disk bytes', true, 'local');
    }

    protected function tearDown(): void
    {
        foreach (array_merge($this->temporary, $this->archives) as $path) {
            if (is_file($path)) { unlink($path); }
        }
        parent::tearDown();
    }

    public function test_portal_zip_preserves_relative_structure_and_excludes_private_hidden_and_cross_folder_files(): void
    {
        $requested = [];
        [$library, $client] = $this->library(function (string $path, ?int $maximumBytes) use (&$requested) {
            $requested[] = $path;
            return $this->fixture($path);
        });
        $response = $library->archive($client, '', true);
        $archive = $response->getFile()->getPathname();
        $this->temporary[] = $archive;
        self::assertSame([
            'Project/report.txt' => 'report bytes',
            'Project/Plans/plan.pdf' => 'nested plan bytes',
        ], $this->zipContents($archive));
        self::assertSame(['Client/Project/report.txt', 'Client/Project/Plans/plan.pdf'], $requested);
        self::assertStringContainsString('private', $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        foreach ($this->temporary as $file) {
            if ($file !== $archive) { self::assertFileDoesNotExist($file); }
        }
    }

    public function test_subfolder_zip_uses_requested_folder_as_root_and_response_removes_archive_after_send(): void
    {
        [$library, $client] = $this->library(fn (string $path, ?int $maximumBytes) => $this->fixture($path));
        $response = $library->archive($client, 'Project', true);
        $archive = $response->getFile()->getPathname();
        $this->temporary[] = $archive;
        self::assertSame(['report.txt' => 'report bytes', 'Plans/plan.pdf' => 'nested plan bytes'], $this->zipContents($archive));
        self::assertStringContainsString('Project.zip', $response->headers->get('Content-Disposition'));
        $response->prepare(Request::create('/archive'));
        ob_start();
        try {
            $response->sendContent();
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertStringStartsWith("PK\x03\x04", $bytes);
        self::assertFileDoesNotExist($archive);
    }

    public function test_admin_zip_includes_private_files_but_keeps_path_boundaries(): void
    {
        [$library, $client] = $this->library(fn (string $path, ?int $maximumBytes) => $this->fixture($path));
        $response = $library->archive($client, '', false);
        $archive = $response->getFile()->getPathname();
        $this->temporary[] = $archive;
        self::assertSame([
            'Project/report.txt' => 'report bytes', 'Project/Plans/plan.pdf' => 'nested plan bytes',
            'Secret/private.txt' => 'private bytes',
        ], $this->zipContents($archive));
    }

    public function test_download_failure_removes_already_fetched_files_and_partial_archive(): void
    {
        $before = glob(sys_get_temp_dir() . '/integratecore-zip-*');
        $reads = 0;
        [$library, $client] = $this->library(function (string $path, ?int $maximumBytes) use (&$reads) {
            if (++$reads === 2) { throw new \RuntimeException('Library read failed'); }
            return $this->fixture($path);
        });
        try {
            $library->archive($client, '', true);
            self::fail('A failed download must stop archive creation.');
        } catch (\RuntimeException $e) {
            self::assertSame('Library read failed', $e->getMessage());
        }
        foreach ($this->temporary as $file) { self::assertFileDoesNotExist($file); }
        self::assertSame($before, glob(sys_get_temp_dir() . '/integratecore-zip-*'));
    }

    public function test_actual_download_sizes_reduce_remaining_budget_and_failure_cleans_partial_archive(): void
    {
        DB::table('documents')->update(['size' => 1]);
        $before = glob(sys_get_temp_dir() . '/integratecore-zip-*');
        $budgets = [];
        [$library, $client] = $this->library(function (string $path, ?int $maximumBytes) use (&$budgets) {
            $budgets[] = $maximumBytes;
            if (count($budgets) === 2) { throw new \RuntimeException('The document exceeds the download size limit.'); }
            return $this->fixture($path);
        });
        try {
            $library->archive($client, '', true);
            self::fail('The download limit failure must stop archive creation.');
        } catch (\RuntimeException $e) {
            self::assertSame('The document exceeds the download size limit.', $e->getMessage());
        }
        self::assertSame([1024 * 1024 * 1024, 1024 * 1024 * 1024 - strlen('report bytes')], $budgets);
        foreach ($this->temporary as $file) { self::assertFileDoesNotExist($file); }
        self::assertSame($before, glob(sys_get_temp_dir() . '/integratecore-zip-*'));
    }

    public function test_zip_finalization_failure_preserves_original_error_and_cleans_all_files(): void
    {
        $before = glob(sys_get_temp_dir() . '/integratecore-zip-*');
        [$library, $client] = $this->library(function (string $path, ?int $maximumBytes) {
            if ($this->temporary !== []) { unlink($this->temporary[0]); }
            return $this->fixture($path);
        });
        // ZipArchive emits a native warning when a queued source disappears.
        // The application must still throw its archive failure and clean up.
        set_error_handler(static fn () => true);
        try {
            try {
                $library->archive($client, '', true);
                self::fail('Missing queued ZIP source must fail finalization.');
            } catch (\RuntimeException $e) {
                self::assertSame('The document archive could not be completed.', $e->getMessage());
            }
        } finally {
            restore_error_handler();
        }
        foreach ($this->temporary as $file) { self::assertFileDoesNotExist($file); }
        self::assertSame($before, glob(sys_get_temp_dir() . '/integratecore-zip-*'));
    }

    public function test_oversized_metadata_is_rejected_before_any_file_download(): void
    {
        DB::table('documents')->where('url', 'Client/Project/report.txt')->update(['size' => 1024 * 1024 * 1024 + 1]);
        [$library, $client] = $this->library(fn () => self::fail('Oversized archives must not download any files.'));
        try {
            $library->archive($client, '', true);
            self::fail('The archive must reject the size limit.');
        } catch (HttpException $e) {
            self::assertSame(422, $e->getStatusCode());
        }
    }

    public function test_private_only_portal_folder_is_not_downloadable(): void
    {
        [$library, $client] = $this->library(fn () => self::fail('Private files must never reach the storage download.'));
        try {
            $library->archive($client, 'Secret', true);
            self::fail('A portal folder without shared files must fail.');
        } catch (HttpException $e) {
            self::assertSame(404, $e->getStatusCode());
        }
    }

    private function addDocument(string $path, string $bytes, bool $public = true, string $disk = 'integratecore'): void
    {
        $this->content[$path] = $bytes;
        DB::table('documents')->insert(['name' => basename($path), 'url' => $path, 'disk' => $disk,
            'is_public' => $public, 'size' => strlen($bytes), 'hash' => bin2hex(random_bytes(12)), 'company_id' => 1]);
    }

    private function library(callable $temporaryPath): array
    {
        $client = new Client();
        $client->id = 7;
        $client->company_id = 1;
        $mapping = new ClientFileFolder(['folder' => 'Client', 'company_id' => 1, 'client_id' => 7]);
        $files = $this->createMock(ClientFiles::class);
        $files->expects(self::once())->method('sync')->with($client, true);
        $files->method('mapping')->willReturn($mapping);
        $files->method('clientDocuments')->willReturnCallback(fn () => Document::query());
        $files->method('path')->willReturnCallback(fn (Document $document) => $document->url);
        $storage = $this->createMock(FileLibrary::class);
        $before = glob(sys_get_temp_dir() . '/integratecore-zip-*');
        $storage->method('temporaryPath')->willReturnCallback(function (string $path, ?int $maximumBytes) use ($temporaryPath, $before) {
            foreach (array_diff(glob(sys_get_temp_dir() . '/integratecore-zip-*'), $before) as $archive) {
                $this->archives[$archive] = $archive;
            }
            return $temporaryPath($path, $maximumBytes);
        });
        return [new DocumentLibrary($files, $storage), $client];
    }

    private function fixture(string $path): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'archive-fixture-');
        $this->temporary[] = $temporary;
        file_put_contents($temporary, $this->content[$path]);
        return $temporary;
    }

    private function zipContents(string $archive): array
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archive));
        try {
            $contents = [];
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $contents[$zip->getNameIndex($index)] = $zip->getFromIndex($index);
            }
            return $contents;
        } finally {
            $zip->close();
        }
    }
}
