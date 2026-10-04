<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error) {
    fwrite(STDERR, 'Native document verification failed: ' . get_class($error) . PHP_EOL);
    exit(1);
});
use App\Models\Client;
use App\Models\Document;
use App\Services\IntegrateCore\ClientFiles;
use App\Services\IntegrateCore\FileLibrary;
use Illuminate\Support\Facades\Storage;

if(config('app.url') !== 'http://192.168.1.119:8082' || config('integratecore.url') !== 'http://file-library' || config('integratecore.library_url') !== 'http://192.168.1.119:8083/files/') { throw new RuntimeException('Dev URL guard failed'); }
$report=[];
function check($condition,$label) { global $report; if(!$condition) throw new RuntimeException($label); $report[]=$label; }
$files=app(ClientFiles::class);$library=app(FileLibrary::class);
$ari=Client::findOrFail(7);$ronnie=Client::findOrFail(6);
$company=$ari->company;
$user=$ari->user;
$token=new App\Models\CompanyToken();
$token->company_id=$company->id;$token->account_id=$company->account_id;$token->user_id=$user->id;
$token->name='client-files-dev-verification';$token->token=bin2hex(random_bytes(32));$token->is_system=true;$token->save();
$http=new GuzzleHttp\Client(['base_uri'=>'http://127.0.0.1','http_errors'=>false,'headers'=>['X-API-TOKEN'=>$token->token,'X-Requested-With'=>'XMLHttpRequest','Accept'=>'application/json']]);
$created=[];
try {
    $migration=Illuminate\Support\Facades\DB::table('client_file_migrations')->where('document_id',1)->first();
    check($migration && Storage::disk($migration->original_disk)->exists($migration->original_url),'Original migration file retained');
    $source=Storage::disk($migration->original_disk)->readStream($migration->original_url);$ctx=hash_init('sha256');hash_update_stream($ctx,$source);fclose($source);
    check(hash_final($ctx)===$migration->sha256 && $library->checksum($migration->library_path)===$migration->sha256,'Migrated original and library checksums match');
    $original=Document::findOrFail(1);
    check($original->documentable_type===App\Models\Project::class && $original->is_public,'Existing project attachment and visibility preserved');
    $count=$files->clientDocuments($ari)->count();$files->sync($ari,true);$files->sync($ari,true);
    check($files->clientDocuments($ari)->count()===$count,'Repeated sync does not duplicate records');
    foreach([$ari,$ronnie] as $client) {
        $response=$http->get('/api/v1/clients/'.$client->hashed_id);$body=json_decode((string)$response->getBody(),true);
        check($response->getStatusCode()===200 && count($body['data']['documents'])>0,'Mobile client API returns documents for client '.$client->id);
        foreach($body['data']['documents'] as $doc) {
            check(!str_starts_with($doc['name'],'.') && !str_contains($doc['name'],'/.'),'Mobile list excludes hidden files');
        }
    }
    $response=$http->put('/api/v1/clients/'.$ronnie->hashed_id.'/file-library',['json'=>['folder'=>'../Ari Miller - FundMax']]);
    check($response->getStatusCode()===422,'Folder traversal is rejected');
    $contents="IntegrateCore dev integration test\n";
    $name='Integration verification '.bin2hex(random_bytes(6)).'.txt';
    $response=$http->put('/api/v1/clients/'.$ari->hashed_id.'/upload',['multipart'=>[['name'=>'documents[]','contents'=>$contents,'filename'=>$name],['name'=>'is_public','contents'=>'false']]]);
    check($response->getStatusCode()===200,'Existing mobile upload endpoint accepts a client document');
    $private=$files->clientDocuments($ari)->where('name',$name)->firstOrFail();$created[]=$private;
    check($private->disk==='integratecore' && !$private->is_public,'Mobile upload stored in library with private visibility');
    $response=$http->get('/api/v1/documents/'.$private->hashed_id.'/download');
    check($response->getStatusCode()===200 && (string)$response->getBody()===$contents,'Mobile download endpoint returns exact original bytes');
    $files->sync($ari,true);check(!Document::find($private->id)->is_public,'Synchronization retains private visibility');
    $own=$ari->contacts->first();$other=$ronnie->contacts->first();
    $request=new App\Http\Requests\ClientPortal\Documents\ShowDocumentRequest();$request->merge(['document'=>$private]);
    auth()->guard('contact')->setUser($own);check(!$request->authorize(),'Own portal contact cannot download a private client document');
    $private->is_public=true;$private->save();check($request->authorize(),'Own portal contact can download a shared client document');
    auth()->guard('contact')->setUser($other);check(!$request->authorize(),'Other client portal contact cannot download the document');
    $bulk=new App\Http\Requests\ClientPortal\Documents\DownloadMultipleDocumentsRequest();$bulk->merge(['file_hash'=>[$private->hashed_id]]);
    check(!$bulk->authorize(),'Other client cannot include this document in a bulk download');
    auth()->guard('contact')->setUser($own);$private->is_public=false;$private->save();check(!$bulk->authorize(),'Private document is excluded from bulk portal download');
    $path=$files->mapping($ari)->folder.'/File manager verification '.bin2hex(random_bytes(6)).'.txt';
    $stream=fopen('php://temp','w+');fwrite($stream,$contents);rewind($stream);$library->upload($path,$stream);if(is_resource($stream))fclose($stream);
    $files->sync($ari,true);$external=$files->clientDocuments($ari)->where('url',$path)->firstOrFail();$created[]=$external;
    check($external->is_public,'File manager upload appears as a shared client document');
    $response=$http->get('/documents/'.$external->hash);
    check($response->getStatusCode()===200 && (string)$response->getBody()===$contents,'Admin preview hash endpoint reads file manager document');
    $spoof=new Document();$spoof->company_id=$ari->company_id;$spoof->documentable_type=Client::class;$spoof->documentable_id=$ari->id;
    $spoof->url=$files->mapping($ronnie)->folder.'/other.txt';
    try { $files->path($spoof); check(false,'Spoofed document must fail'); } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { check($e->getStatusCode()===404,'Stored paths cannot cross client folder boundaries'); }
    $response=$http->delete('/api/v1/documents/'.$external->hashed_id);
    if ($response->getStatusCode()!==200) { echo 'Delete status: '.$response->getStatusCode().' '.substr((string)$response->getBody(),0,300).PHP_EOL; app(App\Repositories\DocumentRepository::class)->delete($external); }
    check(!Document::find($external->id),'Document repository deletes the library file and its record');
    check(!$files->clientDocuments($ari)->where('url',$path)->exists(),'Deleted library document no longer exists in the index');
} finally {
    foreach($created as $document) {
        if(Document::find($document->id)) { $document->deleteFile();$document->forceDelete(); }
    }
    $token->forceDelete();
}
echo json_encode(['passed'=>count($report),'checks'=>array_values(array_unique($report))],JSON_PRETTY_PRINT),PHP_EOL;
