<?php

// Production-only temporary verification credentials. Redirect create stdout privately.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\CompanyToken;
use App\Models\Document;
use App\Services\IntegrateCore\ClientFiles;
use App\Services\IntegrateCore\DocumentLibrary;
use App\Services\IntegrateCore\FileLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

set_exception_handler(static function (Throwable $error) {
    $message = str_starts_with($error->getMessage(), '[QA] ') ? $error->getMessage()
        : 'Production verification stopped: ' . get_class($error) . ' at ' . basename($error->getFile()) . ':' . $error->getLine();
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
});
if (config('app.url') !== 'https://portal.integratecore.net'
    || config('integratecore.url') !== 'http://integratecore-files'
    || !config('integratecore.enabled')) {
    throw new RuntimeException('[QA] Exact production app and internal library guards are required.');
}
$operation = $argv[1] ?? '';
$run = $argv[2] ?? '';
if (!in_array($operation, ['create', 'inspect', 'cleanup'], true) || !preg_match('/^[a-f0-9]{16}$/D', $run)) {
    throw new RuntimeException('[QA] Usage: create|inspect|cleanup <16 lowercase hex run ID>');
}
$ledgerPath = storage_path('app/ic-prod-verification-' . $run . '.json');
$prefix = 'ic-prod-verify-' . $run;
$clients = Client::withoutEagerLoads()->whereIn('id', [7, 6, 10])->get()->keyBy('id');
if ($clients->count() !== 3 || $clients->pluck('company_id')->unique()->count() !== 1) {
    throw new RuntimeException('[QA] Expected production verification clients must share one company.');
}
$companyId = (int) $clients[7]->company_id;
$company = $clients[7]->company;
$files = app(ClientFiles::class);
$storage = app(FileLibrary::class);
$library = app(DocumentLibrary::class);
$balances = static fn () => DB::table('clients')->orderBy('id')->get(['id', 'company_id', 'consulting_hours_balance'])->map(fn ($row) => (array) $row)->all();
$mappingSnapshot = static fn () => DB::table('client_file_folders')->orderBy('id')->get(['company_id', 'client_id', 'folder'])->map(fn ($row) => (array) $row)->all();
$documentSnapshot = static fn () => DB::table('documents')->where('company_id', $companyId)->orderBy('id')
    ->get(['id', 'company_id', 'documentable_type', 'documentable_id', 'disk', 'url', 'hash', 'is_public', 'deleted_at'])
    ->map(fn ($row) => (array) $row)->all();
$saveLedger = static function (array $ledger) use ($ledgerPath) {
    file_put_contents($ledgerPath, json_encode($ledger, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($ledgerPath, 0600);
};
$describeDocument = static function (Document $document, int $clientId) use ($files, $storage) {
    $path = $files->path($document);
    return ['id' => $document->hashed_id, 'numeric_id' => $document->id, 'client_id' => $clientId,
        'name' => $document->name, 'disk' => $document->disk, 'path' => $path,
        'documentable_type' => $document->documentable_type, 'documentable_id' => $document->documentable_id,
        'is_public' => (bool) $document->is_public, 'size' => (int) $document->size,
        'sha256' => $document->disk === 'integratecore' && $document->size <= 25 * 1024 * 1024 ? $storage->checksum($path) : null];
};

if ($operation === 'create') {
    if (is_file($ledgerPath)) throw new RuntimeException('[QA] This run already has a ledger.');
    if (!Schema::hasColumn('companies', 'consulting_hours_custom_field') || (int) $company->consulting_hours_custom_field !== 1
        || data_get($company->custom_fields, 'client1') !== 'Time left (hours)') {
        throw new RuntimeException('[QA] Production migrations and company 1 mobile setup must finish first.');
    }
    $expectedFolders = json_decode((string) getenv('IC_QA_EXPECTED_FOLDERS'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($expectedFolders) || count($expectedFolders) !== 2
        || !array_key_exists(7, $expectedFolders) || !array_key_exists(6, $expectedFolders)) {
        throw new RuntimeException('[QA] Private expected-folder configuration must contain exactly client IDs 7 and 6.');
    }
    foreach ($expectedFolders as $folder) {
        if (!is_string($folder) || strlen($folder) > 191 || str_contains($folder, '/') || !FileLibrary::visiblePath($folder)) {
            throw new RuntimeException('[QA] Private expected-folder configuration contains an invalid folder.');
        }
    }
    if ($expectedFolders[7] === $expectedFolders[6]) {
        throw new RuntimeException('[QA] Production verification clients must have separate folders.');
    }
    foreach ($expectedFolders as $id => $folder) {
        if ($files->mapping($clients[$id])?->folder !== $folder) throw new RuntimeException('[QA] Production folder ownership differs from the planned rollout.');
    }
    if ($files->mapping($clients[10])) throw new RuntimeException('[QA] Dev Account must remain unmapped.');
    $admin = DB::table('company_user')->where('company_id', $companyId)->where(function ($query) {
        $query->where('is_owner', true)->orWhere('is_admin', true);
    })->orderBy('is_owner', 'desc')->orderBy('id')->first(['user_id']);
    if (!$admin) throw new RuntimeException('[QA] An existing administrator is required.');
    // Snapshot all balances before any token/contact writes. No balances are changed.
    $ledger = ['run' => $run, 'company_id' => $companyId, 'account_id' => (int) $company->account_id,
        'user_id' => (int) $admin->user_id, 'balance_snapshot' => $balances(), 'mapping_snapshot' => $mappingSnapshot(),
        'document_snapshot' => $documentSnapshot(), 'clients' => [], 'contacts' => [], 'token' => null];
    foreach ([7, 6, 10] as $id) {
        $client = $clients[$id];
        $mapping = $files->mapping($client);
        $visible = $mapping ? $library->documents($client, '', false) : collect();
        $public = $mapping ? $library->documents($client, '', true) : collect();
        $ledger['clients'][] = ['numeric_id' => $id, 'id' => $client->hashed_id, 'name' => $client->name,
            'balance' => (float) $client->consulting_hours_balance, 'folder' => $mapping?->folder,
            'native_document_ids' => $visible->map(fn ($document) => $document->hashed_id)->values()->all(),
            'public_document_ids' => $public->map(fn ($document) => $document->hashed_id)->values()->all(),
            'documents' => $visible->map(fn ($document) => $describeDocument($document, $id))->values()->all()];
    }
    $saveLedger($ledger);
    \Illuminate\Database\Eloquent\Model::withoutEvents(fn () => DB::transaction(function () use (&$ledger, $clients, $prefix, $companyId, $saveLedger, $run) {
        $token = new CompanyToken();
        $token->company_id = $companyId;
        $token->account_id = $ledger['account_id'];
        $token->user_id = $ledger['user_id'];
        $token->name = $prefix;
        $token->token = bin2hex(random_bytes(32));
        $token->is_system = true;
        $token->saveQuietly();
        $ledger['token'] = ['numeric_id' => $token->id, 'name' => $prefix, 'value' => $token->token];
        $saveLedger($ledger);
        foreach ([7, 6, 10] as $id) {
            $contact = new ClientContact();
            $contact->client_id = $id;
            $contact->company_id = $companyId;
            $contact->user_id = $ledger['user_id'];
            $contact->email = $prefix . '-' . $id . '@example.test';
            $contact->first_name = 'Production QA';
            $contact->last_name = $run;
            $contact->contact_key = Str::random(32);
            $contact->send_email = false;
            $contact->is_primary = false;
            $contact->saveQuietly();
            $ledger['contacts'][] = ['numeric_id' => $contact->id, 'client_id' => $id,
                'email' => $contact->email, 'portal_key' => $contact->contact_key];
            $saveLedger($ledger);
        }
    }));
    // The enclosing arrow closure captures its ledger by value; use its final durable checkpoint.
    $ledger = json_decode(file_get_contents($ledgerPath), true, 512, JSON_THROW_ON_ERROR);
    echo json_encode($ledger, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}
if (!is_file($ledgerPath)) {
    if ($operation === 'cleanup') { echo json_encode(['already_clean' => true]), PHP_EOL; exit; }
    throw new RuntimeException('[QA] No ledger exists for this run.');
}
$ledger = json_decode(file_get_contents($ledgerPath), true, 512, JSON_THROW_ON_ERROR);
if ($ledger['run'] !== $run || $ledger['company_id'] !== $companyId || $ledger['account_id'] !== (int) $company->account_id) {
    throw new RuntimeException('[QA] Ledger ownership mismatch.');
}
$status = ['run' => $run, 'balances_unchanged' => $ledger['balance_snapshot'] === $balances(),
    'mappings_unchanged' => $ledger['mapping_snapshot'] === $mappingSnapshot(),
    'existing_documents_unchanged' => $ledger['document_snapshot'] === $documentSnapshot(),
    'checksums_unchanged' => true, 'contacts' => count($ledger['contacts'])];
foreach ($ledger['clients'] as $client) foreach ($client['documents'] as $document) {
    if ($document['sha256'] === null) continue;
    try {
        if ($storage->checksum($document['path']) !== $document['sha256']) $status['checksums_unchanged'] = false;
    } catch (Throwable $error) {
        $status['checksums_unchanged'] = false;
        $status['checksum_read_errors'] = ($status['checksum_read_errors'] ?? 0) + 1;
    }
}
if ($operation === 'inspect') { echo json_encode($status, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL; exit; }
// Revoke only nonce-owned credentials even if concurrent business activity changed a snapshot.
foreach ($ledger['contacts'] as $fixture) {
    $contact = ClientContact::withTrashed()->find($fixture['numeric_id']);
    if ($contact && ((int) $contact->company_id !== $companyId || (int) $contact->client_id !== $fixture['client_id']
        || $contact->email !== $fixture['email'] || $contact->contact_key !== $fixture['portal_key']
        || (int) $contact->user_id !== $ledger['user_id'] || (bool) $contact->send_email || (bool) $contact->is_primary)) {
        throw new RuntimeException('[QA] A temporary contact no longer matches its nonce ownership.');
    }
}
if ($ledger['token']) {
    $token = CompanyToken::withTrashed()->find($ledger['token']['numeric_id']);
    if ($token && ((int) $token->company_id !== $companyId || (int) $token->account_id !== $ledger['account_id']
        || (int) $token->user_id !== $ledger['user_id'] || $token->name !== $ledger['token']['name']
        || !hash_equals($ledger['token']['value'], $token->token))) {
        throw new RuntimeException('[QA] A temporary token no longer matches its nonce ownership.');
    }
}
\Illuminate\Database\Eloquent\Model::withoutEvents(fn () => DB::transaction(function () use ($ledger) {
    foreach ($ledger['contacts'] as $contact) ClientContact::withTrashed()->where('id', $contact['numeric_id'])->forceDelete();
    if ($ledger['token']) CompanyToken::withTrashed()->where('id', $ledger['token']['numeric_id'])->forceDelete();
}));
unlink($ledgerPath);
$status['removed_contacts'] = count($ledger['contacts']);
$status['removed_tokens'] = $ledger['token'] ? 1 : 0;
echo json_encode($status, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
