<?php

namespace Tests\Unit;

use App\Http\Controllers\ClientPortal\DocumentController;
use App\Http\Requests\ClientPortal\Documents\ShowDocumentRequest;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Document;
use App\Services\IntegrateCore\ClientFiles;
use App\Services\IntegrateCore\DocumentPreview;
use App\Services\IntegrateCore\FileLibrary;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IntegrateCoreDocumentPreviewTest extends TestCase
{
    private array $temporary = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('documents', function (Blueprint $table) {
            $table->id(); $table->integer('company_id'); $table->string('disk'); $table->string('url');
            $table->boolean('is_public'); $table->softDeletes();
        });
        Schema::create('client_contacts', function (Blueprint $table) {
            $table->id(); $table->integer('client_id'); $table->integer('company_id');
            $table->string('email'); $table->softDeletes(); $table->nullableTimestamps();
        });
        DB::table('client_contacts')->insert(['client_id' => 7, 'company_id' => 1, 'email' => 'viewer@example.test']);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $file) {
            if (is_file($file)) { unlink($file); }
        }
        parent::tearDown();
    }

    public function test_preview_routes_use_existing_document_authorization_and_contact_middleware(): void
    {
        foreach (['client.documents.preview', 'client.documents.preview_content'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route);
            self::assertContains('client', $route->middleware());
            self::assertContains('auth:contact', $route->middleware());
            $method = new \ReflectionMethod(DocumentController::class, $route->getActionMethod());
            self::assertSame(ShowDocumentRequest::class, $method->getParameters()[0]->getType()->getName());
        }
    }

    public function test_document_request_rejects_private_cross_client_and_cross_company_previews(): void
    {
        $contact = new ClientContact();
        $contact->forceFill(['id' => 1, 'client_id' => 7, 'company_id' => 1, 'email' => 'viewer@example.test']);
        auth()->guard('contact')->setUser($contact);
        $request = new ShowDocumentRequest();
        $document = $this->document('script.py');
        $request->merge(['document' => $document]);
        self::assertTrue($request->authorize());
        $document->is_public = false;
        self::assertFalse($request->authorize());
        $document->is_public = true;
        $document->documentable_id = 8;
        self::assertFalse($request->authorize());
        $document->documentable_id = 7;
        $document->company_id = 2;
        self::assertFalse($request->authorize());
    }

    public function test_python_deluge_html_and_svg_render_as_escaped_code(): void
    {
        $code = '<script>window.__previewExecuted=true</script><img src=x onerror="alert(1)">';
        foreach (['py', 'deluge', 'html', 'svg'] as $extension) {
            $document = $this->document('script.' . $extension);
            [$service] = $this->service($code);
            $preview = $service->describe($document);
            self::assertSame('text', $preview['kind']);
            $html = $this->renderBody($document, $preview);
            self::assertStringContainsString('&lt;script&gt;window.__previewExecuted=true&lt;/script&gt;', $html);
            self::assertStringNotContainsString('<script>', $html);
            self::assertStringNotContainsString('<img src=x', $html);
            self::assertStringContainsString('<pre tabindex="0"', $html);
            self::assertStringContainsString('/download', $html);
        }
    }

    public function test_text_reads_are_bounded_by_actual_bytes_and_close_the_remote_stream(): void
    {
        [$service, $storage] = $this->service(str_repeat('a', DocumentPreview::TEXT_LIMIT + 100));
        $preview = $service->describe($this->document('large.py', 1));
        self::assertSame(DocumentPreview::TEXT_LIMIT, strlen($preview['text']));
        self::assertTrue($preview['truncated']);
        self::assertSame('text_truncated', $preview['notice']);
        self::assertSame(DocumentPreview::TEXT_LIMIT + 1, $storage->lastStream->bytesRead);
        self::assertTrue($storage->lastStream->closed);
    }

    public function test_binary_script_file_falls_back_instead_of_displaying_binary_bytes(): void
    {
        [$service] = $this->service("binary\0bytes");
        $preview = $service->describe($this->document('script.py'));
        self::assertSame('unsupported', $preview['kind']);
        self::assertSame('binary', $preview['notice']);
        self::assertNull($preview['text']);
    }

    public function test_supported_media_is_sniffed_and_streamed_inline_with_private_headers_and_cleanup(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jxXcAAAAASUVORK5CYII=');
        foreach (['png' => [$png, 'image/png', 'image'], 'pdf' => ["%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF", 'application/pdf', 'pdf']] as $extension => [$bytes, $mime, $kind]) {
            $document = $this->document('preview.' . $extension, strlen($bytes));
            [$service, $storage] = $this->service($bytes);
            self::assertSame($kind, $service->describe($document)['kind']);
            $response = $service->content($document);
            self::assertSame($mime, $response->headers->get('Content-Type'));
            self::assertStringContainsString('private', $response->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            self::assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
            self::assertSame(DocumentPreview::MEDIA_LIMIT, $storage->budget);
            $file = $response->getFile()->getPathname();
            $this->temporary[] = $file;
            $response->prepare(Request::create('/preview/content'));
            ob_start();
            try { $response->sendContent(); $actual = ob_get_contents(); } finally { ob_end_clean(); }
            self::assertSame($bytes, $actual);
            self::assertFileDoesNotExist($file);
        }
    }

    public function test_html_forged_as_pdf_or_image_cannot_be_served_inline_and_temporary_files_are_removed(): void
    {
        foreach (['pdf', 'png'] as $extension) {
            [$service, $storage] = $this->service('<html><script>window.__previewExecuted=true</script></html>');
            $document = $this->document('forged.' . $extension);
            self::assertSame('unsupported', $service->describe($document)['kind']);
            try {
                $service->content($document);
                self::fail('HTML must not be served as inline media.');
            } catch (HttpException $e) {
                self::assertSame(415, $e->getStatusCode());
            }
            foreach ($storage->temporary as $file) { self::assertFileDoesNotExist($file); }
        }
    }

    public function test_unknown_files_and_text_content_endpoint_keep_download_only_fallback(): void
    {
        [$service, $storage] = $this->service('some bytes');
        self::assertSame('unsupported', $service->describe($this->document('archive.zip'))['kind']);
        self::assertSame(0, $storage->reads);
        foreach (['archive.zip', 'script.py', 'markup.html', 'image.svg'] as $name) {
            try { $service->content($this->document($name)); self::fail('Only validated media may be inline.'); }
            catch (HttpException $e) { self::assertSame(415, $e->getStatusCode()); }
        }
    }

    public function test_media_limit_uses_actual_bytes_even_when_document_metadata_is_stale(): void
    {
        [$service] = $this->service(str_repeat('a', DocumentPreview::MEDIA_LIMIT + 1));
        $before = glob(sys_get_temp_dir() . '/integratecore-*');
        try { $service->content($this->document('large.pdf', 1)); self::fail('The actual media bound must be enforced.'); }
        catch (HttpException $e) { self::assertSame(413, $e->getStatusCode()); }
        self::assertSame($before, glob(sys_get_temp_dir() . '/integratecore-*'));
    }

    public function test_declared_large_media_has_accessible_download_fallback_without_reading_remote_bytes(): void
    {
        [$service, $storage] = $this->service('unused bytes');
        $document = $this->document('large.pdf', DocumentPreview::MEDIA_LIMIT + 1);
        $preview = $service->describe($document);
        self::assertSame('media_size', $preview['notice']);
        self::assertSame(0, $storage->reads);
        self::assertStringContainsString('25 MB preview limit', $this->renderBody($document, $preview));
    }

    private function document(string $name, int $size = 12): Document
    {
        $document = new Document();
        $document->forceFill(['id' => 10, 'company_id' => 1, 'documentable_type' => Client::class,
            'documentable_id' => 7, 'name' => $name, 'disk' => 'integratecore', 'url' => 'Client/' . $name,
            'size' => $size, 'is_public' => true]);
        return $document;
    }

    private function service(string $bytes): array
    {
        $files = $this->createMock(ClientFiles::class);
        $files->method('path')->willReturnCallback(fn (Document $document) => $document->url);
        $storage = new class($bytes) extends FileLibrary {
            public int $reads = 0;
            public ?int $budget = null;
            public array $temporary = [];
            public $lastStream;
            public function __construct(private string $bytes) { parent::__construct(); }
            public function read(string $path): ResponseInterface
            {
                ++$this->reads;
                $this->lastStream = new class(\GuzzleHttp\Psr7\Utils::streamFor($this->bytes)) implements \Psr\Http\Message\StreamInterface {
                    use \GuzzleHttp\Psr7\StreamDecoratorTrait;
                    public int $bytesRead = 0;
                    public bool $closed = false;
                    public function read($length): string { $bytes = $this->stream->read($length); $this->bytesRead += strlen($bytes); return $bytes; }
                    public function close(): void { $this->closed = true; $this->stream->close(); }
                };
                return new Response(200, [], $this->lastStream);
            }
            public function temporaryPath(string $path, ?int $maximumBytes = null): string
            {
                $this->budget = $maximumBytes;
                $file = parent::temporaryPath($path, $maximumBytes);
                $this->temporary[] = $file;
                return $file;
            }
        };
        return [new DocumentPreview($files, $storage), $storage];
    }

    private function renderBody(Document $document, array $preview): string
    {
        $source = file_get_contents(resource_path('views/portal/ninja2020/documents/preview.blade.php'));
        $source = preg_replace('/^@extends\([^\n]+\)\n/', '', $source);
        return Blade::render($source . "\n@yield('body')", compact('document', 'preview'), true);
    }
}
