<?php

// Run inside the isolated dev application; stdout contains private session data.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (config('integratecore.url') !== 'http://file-library' || config('app.url') !== 'http://192.168.1.119:8082' || !config('integratecore.enabled')) {
    throw new RuntimeException('This helper requires the isolated client-files dev deployment.');
}

$client = App\Models\Client::findOrFail(7);
$name = 'client-files-browser-verification';
$email = $name . '@example.test';
$operation = $argv[1] ?? 'create';

if ($operation === 'cleanup') {
    Illuminate\Support\Facades\DB::transaction(function () use ($client, $name, $email) {
        App\Models\ClientContact::where('company_id', $client->company_id)->where('client_id', $client->id)
            ->where('email', $email)->forceDelete();
        App\Models\CompanyToken::where('company_id', $client->company_id)->where('account_id', $client->company->account_id)
            ->where('user_id', $client->user_id)->where('name', $name)->forceDelete();
    });
    exit;
}
if ($operation !== 'create') {
    throw new InvalidArgumentException('Choose create or cleanup.');
}

$session = Illuminate\Support\Facades\DB::transaction(function () use ($client, $name, $email) {
    $token = new App\Models\CompanyToken();
    $token->company_id = $client->company_id;
    $token->account_id = $client->company->account_id;
    $token->user_id = $client->user_id;
    $token->name = $name;
    $token->token = bin2hex(random_bytes(32));
    $token->is_system = true;
    $token->save();

    $contact = new App\Models\ClientContact();
    $contact->client_id = $client->id;
    $contact->company_id = $client->company_id;
    $contact->user_id = $client->user_id;
    $contact->email = $email;
    $contact->first_name = 'Library';
    $contact->last_name = 'Verification';
    $contact->contact_key = Illuminate\Support\Str::random(32);
    $contact->send_email = false;
    $contact->is_primary = false;
    $contact->save();

    return ['token' => $token->token, 'portal_key' => $contact->contact_key, 'client_id' => $client->hashed_id,
        'other_client_id' => App\Models\Client::findOrFail(6)->hashed_id];
});
echo json_encode($session, JSON_THROW_ON_ERROR), PHP_EOL;
