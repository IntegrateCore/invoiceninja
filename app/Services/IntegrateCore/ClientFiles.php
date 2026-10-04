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
            'privacy_review_count' => $this->clientDocuments($client)->onlyTrashed()->where('disk', 'integratecore')->where('is_public', false)->count(),
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
                    if (!rewind($source)) {
                        throw new \RuntimeException('The original document could not be rewound. Migration stopped; originals are retained.');
                    }
                    // Reserve the destination before copying. Failed copies must never be
                    // mistaken for newly shared external files by a later synchronization.
                    DB::table('client_file_migrations')->insertOrIgnore([
                        'document_id' => $document->id, 'client_id' => $client->id,
                        'original_disk' => $document->disk, 'original_url' => $document->url,
                        'library_path' => $path, 'sha256' => $checksum,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $reservation = DB::table('client_file_migrations')->where('document_id', $document->id)->first();
                    if (!$reservation || $reservation->client_id != $client->id || $reservation->original_disk !== $document->disk || $reservation->original_url !== $document->url || $reservation->library_path !== $path || !hash_equals($reservation->sha256, $checksum)) {
                        throw new \RuntimeException('The document migration reservation no longer matches the original. Original document retained.');
                    }
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
                    DB::transaction(function () use ($document, $path) {
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
            $existing = $this->clientDocuments($client)->withTrashed()->where('disk', 'integratecore')->get()->keyBy('url');
            $listedPaths = array_fill_keys(array_column($files, 'library_path'), true);
            $privacyReview = $existing->contains(fn ($document) => !$document->is_public && ($document->trashed() || !isset($listedPaths[$document->url])));
            $reserved = DB::table('client_file_migrations')->leftJoin('documents', 'documents.id', '=', 'client_file_migrations.document_id')
                ->where('client_file_migrations.client_id', $client->id)
                ->where(fn ($query) => $query->whereNull('documents.id')->orWhere('documents.disk', '!=', 'integratecore'))
                ->pluck('client_file_migrations.library_path')->flip()->all();
            $seen = [];
            DB::transaction(function () use ($files, $existing, $client, $mapping, $reserved, $privacyReview, &$seen) {
                foreach ($files as $file) {
                    $path = $file['library_path'];
                    if (isset($reserved[$path])) {
                        continue;
                    }
                    $seen[$path] = true;
                    $document = $existing->get($path);
                    if (!$document) {
                        $document = new Document();
                        $document->company_id = $client->company_id;
                        $document->user_id = $client->user_id;
                        $document->disk = 'integratecore';
                        $document->url = $path;
                        $document->name = substr($path, strlen($mapping->folder) + 1);
                        $document->hash = bin2hex(random_bytes(32));
                        $document->is_public = !$privacyReview;
                        $document->documentable_type = Client::class;
                        $document->documentable_id = $client->id;
                    }
                    $document->type = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                    $document->size = $file['size'];
                    if ($document->trashed()) {
                        $document->restore();
                    }
                    if (!$document->exists || $document->isDirty()) {
                        $document->save();
                    }
                }
                foreach ($existing as $path => $document) {
                    if (!isset($seen[$path])) {
                        // The external file disappeared. Remove only its index, never other library files.
                        if ($document->is_public) {
                            $document->forceDelete();
                        } elseif (!$document->trashed()) {
                            // Keep a private tombstone: an external rename or edit cannot
                            // silently turn the unknown replacement into a shared document.
                            $document->delete();
                        }
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
        $client = $entity instanceof Client ? $entity : ($entity?->client_id ? Client::where('company_id', $document->company_id)->find($entity->client_id) : null);
        $mapping = $client ? $this->mapping($client) : null;
        abort_unless($mapping && $entity->company_id === $document->company_id && $client->company_id === $document->company_id && $mapping->company_id === $document->company_id && FileLibrary::inside($document->url, $mapping->folder), 404);
        return $document->url;
    }

    public function upload($file, Client $client, bool $public, $entity = null): Document
    {
        return Cache::lock('integratecore-sync-' . $client->id, 600)->block(10, fn () => $this->uploadLocked($file, $client, $public, $entity));
    }

    private function uploadLocked($file, Client $client, bool $public, $entity): Document
    {
        if ($entity && ($entity->company_id !== $client->company_id || ($entity instanceof Client ? $entity->id !== $client->id : $entity->client_id !== $client->id))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['documents' => 'The document must belong to this client and company.']);
        }
        $mapping = $this->mapping($client);
        $name = $file->getClientOriginalName();
        if (!$mapping || !FileLibrary::visiblePath($name) || str_contains($name, '/')) {
            throw \Illuminate\Validation\ValidationException::withMessages(['documents' => 'Choose a visible file with a valid filename.']);
        }
        $path = $mapping->folder . '/' . $name;
        if ($this->clientDocuments($client)->withTrashed()->where('disk', 'integratecore')->where('url', $path)->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['documents' => 'A file with this name already exists in the client folder.']);
        }
        $stream = fopen($file->getRealPath(), 'rb');
        if (!is_resource($stream)) {
            throw new \RuntimeException('The uploaded document could not be read.');
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
            // Persist visibility first. A timeout can mean that File Browser stored
            // the bytes even though the application never received its response.
            if (!($entity ?? $client)->documents()->save($document)) {
                throw new \RuntimeException('The document record could not be saved.');
            }
            try {
                $this->library->upload($path, $stream);
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                if ($e->getResponse()->getStatusCode() === 409) {
                    // This attempt did not create the remote file. Remove only
                    // its new reservation, leaving the pre-existing file intact.
                    $document->forceDelete();
                    throw \Illuminate\Validation\ValidationException::withMessages(['documents' => 'A file with this name already exists in the client folder.']);
                }
                throw $e;
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $client->touch();
        return $document;
    }
}
