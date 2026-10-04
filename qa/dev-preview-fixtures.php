<?php

// Run only inside the isolated dev container. Creates/removes only this run's fixtures.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Client;
use App\Models\Document;
use App\Services\IntegrateCore\ClientFiles;
use App\Services\IntegrateCore\FileLibrary;
use Illuminate\Http\UploadedFile;

if (config('integratecore.url') !== 'http://file-library'
    || config('app.url') !== 'http://192.168.1.119:8082'
    || !config('integratecore.enabled')) {
    throw new RuntimeException('Preview fixtures require the isolated dev application.');
}
$operation = $argv[1] ?? '';
$run = $argv[2] ?? '';
if (!in_array($operation, ['create', 'cleanup'], true) || !preg_match('/^[a-f0-9]{16}$/D', $run)) {
    throw new InvalidArgumentException('Usage: php dev-preview-fixtures.php create|cleanup <16 lowercase hex run ID>');
}
$ledgerPath = storage_path('app/ic-preview-qa-' . $run . '.json');
$prefix = 'Preview QA ' . $run . ' ';
$files = app(ClientFiles::class);
$library = app(FileLibrary::class);
$clients = [7 => Client::findOrFail(7), 6 => Client::findOrFail(6)];
if ($clients[7]->company_id !== $clients[6]->company_id) {
    throw new RuntimeException('Fixture clients must belong to the same dev company.');
}
foreach ($clients as $client) {
    if (!$files->mapping($client)) {
        throw new RuntimeException('Both dev fixture clients require existing library assignments.');
    }
}

if ($operation === 'cleanup') {
    if (!is_file($ledgerPath)) {
        echo json_encode(['run' => $run, 'removed' => 0, 'already_clean' => true]), PHP_EOL;
        exit;
    }
    $ledger = json_decode(file_get_contents($ledgerPath), true, 512, JSON_THROW_ON_ERROR);
    if (($ledger['run'] ?? '') !== $run) {
        throw new RuntimeException('Fixture ledger run ID mismatch.');
    }
    $removed = 0;
    foreach ($ledger['fixtures'] as $fixture) {
        $document = Document::withTrashed()->find($fixture['numeric_id']);
        if (!$document) {
            continue;
        }
        $client = $clients[$fixture['numeric_client_id']] ?? null;
        $expectedPath = $client ? $files->mapping($client)->folder . '/' . $fixture['name'] : '';
        if (!$client || $document->company_id !== $client->company_id
            || $document->documentable_type !== Client::class || $document->documentable_id !== $client->id
            || $document->disk !== 'integratecore' || $document->hash !== $fixture['hash']
            || $document->name !== $fixture['name'] || !str_starts_with($document->name, $prefix)
            || $document->url !== $expectedPath) {
            throw new RuntimeException('Cleanup refuses a document that no longer matches its fixture ledger.');
        }
        if ($library->checksum($expectedPath) !== $fixture['sha256']) {
            throw new RuntimeException('Cleanup refuses fixture bytes that were changed after creation.');
        }
        $document->deleteFile();
        $document->forceDelete();
        $client->touch(); // Let incremental native sync remove this temporary attachment.
        $removed++;
    }
    unlink($ledgerPath);
    echo json_encode(['run' => $run, 'removed' => $removed]), PHP_EOL;
    exit;
}

if (is_file($ledgerPath)) {
    throw new RuntimeException('This run already has a ledger; clean it before creating fixtures again.');
}
$ledger = ['run' => $run, 'client_id' => $clients[7]->hashed_id, 'other_client_id' => $clients[6]->hashed_id, 'fixtures' => []];
$saveLedger = static function () use (&$ledger, $ledgerPath) {
    file_put_contents($ledgerPath, json_encode($ledger, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($ledgerPath, 0600);
};
$saveLedger();

// A minimal valid PDF with a real cross-reference table and one text page.
function fixturePdf(): string
{
    $stream = "BT /F1 16 Tf 30 80 Td (Preview QA PDF) Tj ET\n";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 240 120] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . 'endstream',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    return $pdf . "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
}

$marker = '<script>window.__icPreviewExecuted = true;</script>';
$cases = [
    ['kind' => 'image', 'suffix' => 'image.png', 'mime' => 'image/png', 'bytes' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGOwbvr2HwAFYgKzn+LXXwAAAABJRU5ErkJggg==', true)],
    ['kind' => 'pdf', 'suffix' => 'document.pdf', 'mime' => 'application/pdf', 'bytes' => fixturePdf()],
    ['kind' => 'python', 'suffix' => 'script.py', 'mime' => 'text/plain', 'bytes' => "#!/usr/bin/env python3\n# {$marker}\nprint('Preview QA Python')\n"],
    ['kind' => 'deluge', 'suffix' => 'script.deluge', 'mime' => 'text/plain', 'bytes' => "// Preview QA Deluge\ninfo \"{$marker}\";\n"],
    ['kind' => 'html', 'suffix' => 'script.html', 'mime' => 'text/html', 'bytes' => "<!doctype html><h1>Preview QA HTML</h1><script>window.__previewExecuted=true</script>\n"],
    ['kind' => 'forged_image', 'suffix' => 'forged.png', 'mime' => 'image/png', 'bytes' => "<script>window.__previewExecuted=true</script>\n"],
    ['kind' => 'forged_pdf', 'suffix' => 'forged.pdf', 'mime' => 'application/pdf', 'bytes' => "<script>window.__previewExecuted=true</script>\n"],
    ['kind' => 'unsupported', 'suffix' => 'unsupported.bin', 'mime' => 'application/octet-stream', 'bytes' => "\x00\x01Preview QA unsupported\xff"],
    ['kind' => 'private', 'suffix' => 'private.txt', 'mime' => 'text/plain', 'bytes' => "Preview QA private file\n", 'public' => false],
    ['kind' => 'cross_client', 'suffix' => 'other-client.txt', 'mime' => 'text/plain', 'bytes' => "Preview QA other client file\n", 'client' => 6],
];
foreach ($cases as $case) {
    $client = $clients[$case['client'] ?? 7];
    $temporary = tempnam(sys_get_temp_dir(), 'ic-preview-upload-');
    if ($temporary === false) {
        throw new RuntimeException('Cannot create temporary fixture upload.');
    }
    try {
        file_put_contents($temporary, $case['bytes']);
        $name = $prefix . $case['suffix'];
        $uploaded = new UploadedFile($temporary, $name, $case['mime'], null, true);
        $document = $files->upload($uploaded, $client, $case['public'] ?? true);
        $ledger['fixtures'][] = [
            'kind' => $case['kind'], 'name' => $name, 'id' => $document->hashed_id,
            'numeric_id' => $document->id, 'numeric_client_id' => $client->id,
            'hash' => $document->hash, 'sha256' => hash('sha256', $case['bytes']),
            'mime' => $case['mime'], 'size' => strlen($case['bytes']),
            'public' => $case['public'] ?? true,
            'text' => in_array($case['kind'], ['python', 'deluge', 'html'], true) ? $case['bytes'] : null,
        ];
        $saveLedger();
    } finally {
        unlink($temporary);
    }
}
echo json_encode($ledger, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
