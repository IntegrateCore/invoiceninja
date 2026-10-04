<?php

namespace App\Services\IntegrateCore;

use App\Models\Client;
use App\Models\ClientFileFolder;
use App\Models\Document;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ClientFiles
{
    public function __construct(private FileLibrary $library) {}

    public function mapping(Client $client): ?ClientFileFolder
    {
        return ClientFileFolder::where('client_id', $client->id)->where('company_id', $client->company_id)->first();
    }

    public function clientDocuments(Client $client)
    {
        return Document::where('company_id', $client->company_id)->where(function ($query) use ($client) {
            $query->where(function ($q) use ($client) {
                $q->where('documentable_type', Client::class)->where('documentable_id', $client->id);
            })->orWhereHasMorph('documentable', [\App\Models\Invoice::class, \App\Models\Quote::class, \App\Models\Credit::class, \App\Models\Expense::class, \App\Models\Payment::class, \App\Models\Task::class, \App\Models\Project::class, \App\Models\RecurringInvoice::class, \App\Models\RecurringExpense::class], fn ($q) => $q->where('client_id', $client->id));
        });
    }

    public function assign(Client $client, string $folder): array
    {
        return Cache::lock('integratecore-folder-assignments', 180)->block(10, function () use ($client, $folder) {
            $available = array_column(array_filter($this->library->entries(), fn ($entry) => $entry['isDir']), 'name');
            if (!in_array($folder, $available, true)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['folder' => 'Choose an existing client folder.']);
            }
            foreach (ClientFileFolder::all() as $mapping) {
                if ($mapping->client_id != $client->id && ($mapping->folder === $folder || FileLibrary::inside($folder, $mapping->folder) || FileLibrary::inside($mapping->folder, $folder))) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['folder' => 'This folder is already assigned to another client.']);
                }
            }
            $existing = $this->mapping($client);
            if ($existing && $existing->folder !== $folder) {
                throw \Illuminate\Validation\ValidationException::withMessages(['folder' => 'This client already has a folder. Move its documents before changing the assignment.']);
            }
            ClientFileFolder::firstOrCreate(['client_id' => $client->id], ['company_id' => $client->company_id, 'folder' => $folder]);
            $this->migrate($client);
            $this->sync($client, true);
            return $this->status($client);
        });
    }

    public function status(Client $client): array
    {
        $mapping = $this->mapping($client);
        return [
            'enabled' => (bool) config('integratecore.enabled'),
            'folder' => $mapping?->folder,
            'library_url' => $mapping ? rtrim(config('integratecore.library_url'), '/') . '/' . rawurlencode($mapping->folder) . '/' : null,
            'pending_migrations' => $this->clientDocuments($client)->where('disk', '!=', 'integratecore')->count(),
            'document_count' => $this->clientDocuments($client)->where('disk', 'integratecore')->count(),
        ];
    }

    public function migrate(Client $client, bool $dryRun = false): array
    {
        $mapping = $this->mapping($client);
        if (!$mapping) {
            return [];
        }
        return Cache::lock('integratecore-sync-' . $client->id, 600)->block(10, function () use ($client, $mapping, $dryRun) {
            $report = [];
            foreach ($this->clientDocuments($client)->where('disk', '!=', 'integratecore')->get() as $document) {
                if (!FileLibrary::visiblePath($document->name)) {
                    continue;
                }
                $extension = pathinfo($document->name, PATHINFO_EXTENSION);
                $stem = pathinfo($document->name, PATHINFO_FILENAME);
                $name = $stem . ' (Ninja ' . $document->hashed_id . ')' . ($extension ? '.' . $extension : '');
                $path = $mapping->folder . '/' . $name;
                $report[] = ['document_id' => $document->hashed_id, 'destination' => $path, 'is_public' => $document->is_public];
                if ($dryRun) {
                    continue;
                }
                $source = Storage::disk($document->disk)->readStream($document->url);
                if (!is_resource($source)) {
                    throw new \RuntimeException('An original document could not be read. Migration stopped; originals are retained.');
                }
                try {
                    $context = hash_init('sha256');
                    hash_update_stream($context, $source);
                    $checksum = hash_final($context);
                    rewind($source);
                    try {
                        $this->library->upload($path, $source);
                    } catch (\GuzzleHttp\Exception\ClientException $e) {
                        if ($e->getResponse()->getStatusCode() !== 409) {
                            throw $e;
                        }
                        // A retry may find an already copied file. It must match the original.
                    }
                    if (!hash_equals($checksum, $this->library->checksum($path))) {
                        throw new \RuntimeException('Document checksum mismatch. Original document retained.');
                    }
                    DB::transaction(function () use ($client, $document, $path, $checksum) {
                        DB::table('client_file_migrations')->insertOrIgnore([
                            'document_id' => $document->id, 'client_id' => $client->id,
                            'original_disk' => $document->disk, 'original_url' => $document->url,
                            'library_path' => $path, 'sha256' => $checksum,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        $document->disk = 'integratecore';
                        $document->url = $path;
                        $document->save();
                    });
                } finally {
                    if (is_resource($source)) {
                        fclose($source);
                    }
                }
            }
            return $report;
        });
    }

    public function sync(Client $client, bool $force = false): void
    {
        if (!config('integratecore.enabled') || !($mapping = $this->mapping($client))) {
            return;
        }
        $key = 'integratecore-last-sync-' . $client->id;
        if (!$force && Cache::has($key)) {
            return;
        }
        $lock = Cache::lock('integratecore-sync-' . $client->id, 180);
        if (!$lock->get()) {
            return;
        }
        try {
            // Complete listing before any record changes. A disconnected library must never look empty.
            $files = $this->library->files($mapping->folder);
            $existing = $this->clientDocuments($client)->where('disk', 'integratecore')->get()->keyBy('url');
            $seen = [];
            DB::transaction(function () use ($files, $existing, $client, $mapping, &$seen) {
                foreach ($files as $file) {
                    $path = $file['library_path'];
                    $seen[] = $path;
                    $document = $existing->get($path);
                    if (!$document) {
                        $document = new Document();
                        $document->company_id = $client->company_id;
                        $document->user_id = $client->user_id;
                        $document->disk = 'integratecore';
                        $document->url = $path;
                        $document->name = substr($path, strlen($mapping->folder) + 1);
                        $document->hash = bin2hex(random_bytes(32));
                        $document->is_public = true;
                        $document->documentable_type = Client::class;
                        $document->documentable_id = $client->id;
                    }
                    $document->type = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                    $document->size = $file['size'];
                    if (!$document->exists || $document->isDirty()) {
                        $document->save();
                    }
                }
                foreach ($existing as $path => $document) {
                    if (!in_array($path, $seen, true)) {
                        // The external file disappeared. Remove only its index, never other library files.
                        $document->forceDelete();
                    }
                }
            });
            $client->unsetRelation('documents');
            Cache::put($key, true, 15);
        } finally {
            $lock->release();
        }
    }

    public function path(Document $document): string
    {
        $entity = $document->documentable;
        $client = $entity instanceof Client ? $entity : ($entity?->client_id ? Client::find($entity->client_id) : null);
        $mapping = $client ? $this->mapping($client) : null;
        abort_unless($mapping && $mapping->company_id === $document->company_id && FileLibrary::inside($document->url, $mapping->folder), 404);
        return $document->url;
    }

    public function upload($file, Client $client, bool $public, $entity = null): Document
    {
        return Cache::lock('integratecore-sync-' . $client->id, 600)->block(10, fn () => $this->uploadLocked($file, $client, $public, $entity));
    }

    private function uploadLocked($file, Client $client, bool $public, $entity): Document
    {
        $mapping = $this->mapping($client);
        $name = $file->getClientOriginalName();
        if (!$mapping || !FileLibrary::visiblePath($name) || str_contains($name, '/')) {
            throw \Illuminate\Validation\ValidationException::withMessages(['documents' => 'Choose a visible file with a valid filename.']);
        }
        $path = $mapping->folder . '/' . $name;
        $stream = fopen($file->getRealPath(), 'rb');
        try {
            $this->library->upload($path, $stream);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 409) {
                throw \Illuminate\Validation\ValidationException::withMessages(['documents' => 'A file with this name already exists in the client folder.']);
            }
            throw $e;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $document = new Document();
        $document->user_id = $client->user_id;
        $document->company_id = $client->company_id;
        $document->name = $name;
        $document->url = $path;
        $document->disk = 'integratecore';
        $document->type = strtolower($file->getClientOriginalExtension());
        $document->hash = bin2hex(random_bytes(32));
        $document->size = $file->getSize();
        $document->is_public = $public;
        try {
            ($entity ?? $client)->documents()->save($document);
        } catch (\Throwable $e) {
            $this->library->delete($path);
            throw $e;
        }
        $client->touch();
        return $document;
    }
}
