<?php

// Stdout from create contains temporary credentials; redirect it to a private .local file.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Factory\ClientFactory;
use App\Factory\ProjectFactory;
use App\Factory\UserFactory;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\ClientFileFolder;
use App\Models\CompanyToken;
use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use App\Services\IntegrateCore\ClientFiles;
use App\Services\IntegrateCore\FileLibrary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

set_exception_handler(static function (Throwable $error) {
    $message = get_class($error) === RuntimeException::class || get_class($error) === InvalidArgumentException::class
        ? $error->getMessage() : 'A guarded fixture operation failed: ' . get_class($error);
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
});

if (config('integratecore.url') !== 'http://file-library'
    || config('app.url') !== 'http://192.168.1.119:8082'
    || !config('integratecore.enabled')) {
    throw new RuntimeException('Folder QA requires the isolated dev deployment.');
}
$operation = $argv[1] ?? '';
$run = $argv[2] ?? '';
if (!in_array($operation, ['create', 'inspect', 'cleanup'], true) || !preg_match('/^[a-f0-9]{16}$/D', $run)) {
    throw new InvalidArgumentException('Usage: php dev-folder-pointer-fixtures.php create|inspect|cleanup <16 lowercase hex run ID>');
}
$prefix = 'Folder QA ' . $run;
$ledgerPath = storage_path('app/ic-folder-qa-' . $run . '.json');
$files = app(ClientFiles::class);
$library = app(FileLibrary::class);
$owner = Client::withoutEagerLoads()->findOrFail(7);
$company = $owner->company;
$companyId = (int) $company->id;
$accountId = (int) $company->account_id;
$businessMappings = static fn (array $ownClients = []) => DB::table('client_file_folders')
    ->whereNotIn('client_id', $ownClients)->orderBy('id')->get(['company_id', 'client_id', 'folder'])
    ->map(static fn ($row) => (array) $row)->all();
$saveLedger = static function (array $ledger) use ($ledgerPath) {
    file_put_contents($ledgerPath, json_encode($ledger, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($ledgerPath, 0600);
};

if ($operation === 'create') {
    if (is_file($ledgerPath)) {
        throw new RuntimeException('A fixture ledger already exists for this run.');
    }
    if (!Schema::hasTable('client_file_document_references') || !Schema::hasTable('client_file_folder_tenants')) {
        throw new RuntimeException('Deploy and migrate the folder-pointer backend before creating fixtures.');
    }
    $folders = [$prefix . ' A', $prefix . ' B'];
    $existing = array_column($library->entries(), 'name');
    foreach ($folders as $folder) {
        if (in_array($folder, $existing, true) || ClientFileFolder::where('folder', $folder)->exists()) {
            throw new RuntimeException('A proposed QA folder already exists; no changes were made.');
        }
    }
    $ledger = ['run' => $run, 'company_id' => $companyId, 'account_id' => $accountId,
        'business_mappings' => $businessMappings(), 'folders' => [], 'clients' => [],
        'contacts' => [], 'files' => [], 'project' => null, 'user' => null, 'token' => null];
    $saveLedger($ledger);
    foreach ($folders as $folder) {
        $library->request('POST', 'resources/' . rawurlencode($folder) . '/', ['body' => '']);
        $ledger['folders'][] = $folder;
        $saveLedger($ledger);
    }
    for ($index = 1; $index <= 2; $index++) {
        $client = ClientFactory::create($companyId, $owner->user_id);
        $client->name = $prefix . ' Client ' . $index;
        $client->number = 'QA-FOLDER-' . $run . '-' . $index;
        $client->public_notes = implode("\n", array_fill(0, 15, 'Temporary folder QA notes for card sizing.'));
        $settings = $client->settings;
        $settings->currency_id = (string) $company->settings->currency_id;
        $client->settings = $settings;
        $client->saveQuietly();
        $ledger['clients'][] = ['numeric_id' => $client->id, 'id' => $client->hashed_id, 'name' => $client->name, 'number' => $client->number];
        $saveLedger($ledger);
        $contact = new ClientContact();
        $contact->client_id = $client->id;
        $contact->company_id = $companyId;
        $contact->user_id = $owner->user_id;
        $contact->email = 'folder-qa-' . $run . '-' . $index . '@example.test';
        $contact->first_name = 'Folder QA';
        $contact->last_name = (string) $index;
        $contact->contact_key = Str::random(32);
        $contact->send_email = false;
        $contact->is_primary = true;
        $contact->saveQuietly();
        $ledger['contacts'][] = ['numeric_id' => $contact->id, 'client_id' => $client->id, 'email' => $contact->email, 'portal_key' => $contact->contact_key];
        $saveLedger($ledger);
    }
    $first = Client::withoutEagerLoads()->findOrFail($ledger['clients'][0]['numeric_id']);
    $project = ProjectFactory::create($companyId, $owner->user_id);
    $project->client_id = $first->id;
    $project->name = $prefix . ' Project';
    $project->number = 'QA-PROJECT-' . $run;
    $project->saveQuietly();
    $ledger['project'] = ['numeric_id' => $project->id, 'id' => $project->hashed_id, 'name' => $project->name, 'client_id' => $first->id];
    $saveLedger($ledger);

    // This temporary mapping is used only to make normal Document upload records.
    // Remove it before tests so the manager starts with both QA folders unassigned.
    ClientFileFolder::create(['company_id' => $companyId, 'client_id' => $first->id, 'folder' => $folders[0]]);
    $cases = [
        ['folder' => $folders[0], 'name' => 'public-a.txt', 'bytes' => "{$prefix} public A\n", 'public' => true, 'entity' => null],
        ['folder' => $folders[0], 'name' => 'private-a.txt', 'bytes' => "{$prefix} private A\n", 'public' => false, 'entity' => null],
        ['folder' => $folders[0], 'name' => 'project-a.txt', 'bytes' => "{$prefix} original project attachment\n", 'public' => true, 'entity' => $project],
        ['folder' => $folders[1], 'name' => 'public-b.txt', 'bytes' => "{$prefix} public B\n", 'public' => true, 'entity' => null],
    ];
    foreach ($cases as $case) {
        $fixture = ['path' => $case['folder'] . '/' . $case['name'], 'folder' => $case['folder'], 'name' => $case['name'],
            'sha256' => hash('sha256', $case['bytes']), 'size' => strlen($case['bytes']), 'is_public' => $case['public'],
            'numeric_id' => null, 'id' => null, 'hash' => null,
            'documentable_type' => $case['entity'] ? Project::class : Client::class,
            'documentable_id' => $case['entity'] ? $project->id : $first->id];
        $ledger['files'][] = $fixture;
        $saveLedger($ledger);
        if ($case['folder'] === $folders[0]) {
            $temporary = tempnam(sys_get_temp_dir(), 'ic-folder-upload-');
            try {
                file_put_contents($temporary, $case['bytes']);
                $upload = new UploadedFile($temporary, $case['name'], 'text/plain', null, true);
                $document = $files->upload($upload, $first, $case['public'], $case['entity']);
                $fixture['numeric_id'] = $document->id;
                $fixture['id'] = $document->hashed_id;
                $fixture['hash'] = $document->hash;
                $ledger['files'][array_key_last($ledger['files'])] = $fixture;
                $saveLedger($ledger);
            } finally {
                unlink($temporary);
            }
        } else {
            $stream = fopen('php://temp', 'w+');
            fwrite($stream, $case['bytes']);
            rewind($stream);
            try {
                $library->upload($fixture['path'], $stream);
            } finally {
                if (is_resource($stream)) fclose($stream);
            }
        }
    }
    ClientFileFolder::where('client_id', $first->id)->delete();

    $user = UserFactory::create($accountId);
    $user->first_name = 'Folder QA';
    $user->last_name = $run;
    $user->email = 'folder-qa-editor-' . $run . '@example.test';
    $user->password = Hash::make(bin2hex(random_bytes(24)));
    $user->email_verified_at = now();
    $user->user_logged_in_notification = false;
    $user->saveQuietly();
    $ledger['user'] = ['numeric_id' => $user->id, 'email' => $user->email];
    $saveLedger($ledger);
    $pivotId = DB::table('company_user')->insertGetId([
        'company_id' => $companyId, 'account_id' => $accountId, 'user_id' => $user->id,
        'permissions' => 'view_client,edit_client,view_project', 'is_owner' => false, 'is_admin' => false,
        'is_locked' => false, 'slack_webhook_url' => '', 'settings' => '{}', 'react_settings' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $ledger['user']['pivot_id'] = $pivotId;
    $saveLedger($ledger);
    $token = new CompanyToken();
    $token->company_id = $companyId;
    $token->account_id = $accountId;
    $token->user_id = $user->id;
    $token->name = 'folder-qa-editor-' . $run;
    $token->token = bin2hex(random_bytes(32));
    $token->is_system = true;
    $token->saveQuietly();
    $ledger['token'] = ['numeric_id' => $token->id, 'name' => $token->name, 'value' => $token->token];
    $saveLedger($ledger);
    echo json_encode($ledger, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}

if (!is_file($ledgerPath)) {
    if ($operation === 'cleanup') {
        echo json_encode(['run' => $run, 'already_clean' => true]), PHP_EOL;
        exit;
    }
    throw new RuntimeException('The fixture ledger is missing.');
}
$ledger = json_decode(file_get_contents($ledgerPath), true, 512, JSON_THROW_ON_ERROR);
if ($ledger['run'] !== $run || $ledger['company_id'] !== $companyId || $ledger['account_id'] !== $accountId) {
    throw new RuntimeException('Fixture ledger ownership mismatch.');
}
$clientIds = array_column($ledger['clients'], 'numeric_id');
if ($businessMappings($clientIds) !== $ledger['business_mappings']) {
    throw new RuntimeException('An existing business-client mapping changed during folder QA.');
}
$knownFiles = array_column($ledger['files'], null, 'path');
$documentIds = [];
$documents = [];
foreach (Document::withTrashed()->where('company_id', $companyId)->whereIn('url', array_keys($knownFiles))->get() as $document) {
    $owned = $document->documentable_type === Client::class && in_array((int) $document->documentable_id, $clientIds, true);
    $owned = $owned || ($document->documentable_type === Project::class && (int) $document->documentable_id === (int) ($ledger['project']['numeric_id'] ?? 0));
    if (!$owned || $document->disk !== 'integratecore') {
        throw new RuntimeException('A fixture document now references a non-QA entity; no cleanup will proceed.');
    }
    $documentIds[] = $document->id;
    $documents[] = ['numeric_id' => $document->id, 'id' => $document->hashed_id, 'hash' => $document->hash,
        'path' => $document->url, 'is_public' => (bool) $document->is_public, 'deleted' => $document->trashed(),
        'documentable_type' => $document->documentable_type, 'documentable_id' => (int) $document->documentable_id];
}
$checksums = [];
foreach ($ledger['folders'] as $folder) {
    foreach ($library->files($folder) as $entry) {
        $path = $entry['library_path'];
        if (!isset($knownFiles[$path])) {
            throw new RuntimeException('A QA folder contains an unknown file; no cleanup will proceed.');
        }
        $checksum = $library->checksum($path);
        if ($checksum !== $knownFiles[$path]['sha256']) {
            throw new RuntimeException('Fixture file bytes changed; no cleanup will proceed.');
        }
        $checksums[$path] = $checksum;
    }
}
if ($operation === 'inspect') {
    echo json_encode(['run' => $run, 'business_mappings_unchanged' => true, 'checksums' => $checksums,
        'mappings' => ClientFileFolder::whereIn('client_id', $clientIds)->get(['client_id', 'folder'])->map(fn ($row) => ['client_id' => $row->client_id, 'folder' => $row->folder])->all(),
        'documents' => $documents,
        'tenants' => DB::table('client_file_folder_tenants')->whereIn('folder', $ledger['folders'])->get(['folder', 'company_id'])->map(fn ($row) => (array) $row)->all(),
        'references' => DB::table('client_file_document_references')->whereIn('document_id', $documentIds)
            ->get(['document_id', 'client_id', 'documentable_type', 'documentable_id', 'library_path'])->map(fn ($row) => (array) $row)->all()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}

foreach (ClientFileFolder::whereIn('folder', $ledger['folders'])->get() as $mapping) {
    if ((int) $mapping->company_id !== $companyId || !in_array((int) $mapping->client_id, $clientIds, true)) {
        throw new RuntimeException('A QA folder is now assigned to a non-QA client; no cleanup will proceed.');
    }
}
foreach ($ledger['clients'] as $fixture) {
    $client = Client::withTrashed()->withoutEagerLoads()->findOrFail($fixture['numeric_id']);
    if ((int) $client->company_id !== $companyId || $client->name !== $fixture['name'] || $client->number !== $fixture['number']) {
        throw new RuntimeException('A temporary client no longer matches its fixture ledger.');
    }
    foreach (['invoices', 'quotes', 'credits', 'expenses', 'payments', 'tasks', 'recurring_invoices', 'recurring_expenses'] as $table) {
        if (DB::table($table)->where('client_id', $client->id)->exists()) {
            throw new RuntimeException('A temporary client has a non-QA business entity; no cleanup will proceed.');
        }
    }
    if (Project::withTrashed()->where('client_id', $client->id)->where('id', '!=', $ledger['project']['numeric_id'] ?? 0)->exists()) {
        throw new RuntimeException('A temporary client has a non-QA project; no cleanup will proceed.');
    }
    if ($files->clientDocuments($client)->withTrashed()->whereNotIn('id', $documentIds)->exists()) {
        throw new RuntimeException('A temporary client has a non-QA attachment; no cleanup will proceed.');
    }
}
if ($ledger['project']) {
    $project = Project::withTrashed()->findOrFail($ledger['project']['numeric_id']);
    if ((int) $project->company_id !== $companyId || $project->name !== $ledger['project']['name'] || (int) $project->client_id !== $ledger['project']['client_id']) {
        throw new RuntimeException('A temporary project no longer matches its fixture ledger.');
    }
}
if ($ledger['user']) {
    $user = User::withTrashed()->findOrFail($ledger['user']['numeric_id']);
    if ((int) $user->account_id !== $accountId || $user->email !== $ledger['user']['email']) {
        throw new RuntimeException('A temporary user no longer matches its fixture ledger.');
    }
}
// Validate everything before removing any physical bytes or fixture rows.
foreach ($checksums as $path => $_) $library->delete($path);
foreach ($ledger['folders'] as $folder) {
    if ($library->entries($folder) !== []) throw new RuntimeException('A QA directory is not empty.');
    $library->delete($folder);
}
\Illuminate\Database\Eloquent\Model::withoutEvents(fn () => DB::transaction(function () use ($ledger, $clientIds, $documentIds, $companyId) {
    DB::table('client_file_document_references')->whereIn('document_id', $documentIds)->delete();
    DB::table('client_file_migrations')->whereIn('document_id', $documentIds)->delete();
    Document::withTrashed()->whereIn('id', $documentIds)->forceDelete();
    ClientFileFolder::where('company_id', $companyId)->whereIn('client_id', $clientIds)->delete();
    DB::table('client_file_folder_tenants')->where('company_id', $companyId)->whereIn('folder', $ledger['folders'])->delete();
    foreach ($ledger['contacts'] as $contact) {
        ClientContact::withTrashed()->where('id', $contact['numeric_id'])->where('company_id', $companyId)
            ->where('client_id', $contact['client_id'])->where('email', $contact['email'])->forceDelete();
    }
    if ($ledger['project']) Project::withTrashed()->where('id', $ledger['project']['numeric_id'])->forceDelete();
    Client::withTrashed()->where('company_id', $companyId)->whereIn('id', $clientIds)->forceDelete();
    if ($ledger['token']) CompanyToken::withTrashed()->where('id', $ledger['token']['numeric_id'])->where('name', $ledger['token']['name'])->forceDelete();
    if ($ledger['user']) {
        DB::table('company_user')->where('user_id', $ledger['user']['numeric_id'])->where('company_id', $companyId)->delete();
        User::withTrashed()->where('id', $ledger['user']['numeric_id'])->forceDelete();
    }
}));
unlink($ledgerPath);
echo json_encode(['run' => $run, 'removed_files' => count($checksums), 'removed_documents' => count($documentIds),
    'removed_clients' => count($clientIds), 'business_mappings_unchanged' => true]), PHP_EOL;
