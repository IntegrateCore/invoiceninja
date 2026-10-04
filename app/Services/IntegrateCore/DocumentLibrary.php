<?php

namespace App\Services\IntegrateCore;

use App\Models\Client;
use Illuminate\Support\Collection;

class DocumentLibrary
{
    public function __construct(private ClientFiles $files, private FileLibrary $storage) {}

    public function documents(Client $client, string $path, bool $publicOnly): Collection
    {
        abort_unless($path === '' || FileLibrary::visiblePath($path), 422);
        $mapping = $this->files->mapping($client);
        abort_unless($mapping, 404);
        $prefix = $mapping->folder . '/' . ($path === '' ? '' : $path . '/');
        return $this->files->clientDocuments($client)->where('disk', 'integratecore')
            ->when($publicOnly, fn ($q) => $q->where('is_public', true))->get()
            ->filter(fn ($doc) => FileLibrary::inside($doc->url, $mapping->folder) && str_starts_with($doc->url, $prefix));
    }

    public function listing(Client $client, string $path, bool $publicOnly): array
    {
        $this->files->sync($client);
        $mapping = $this->files->mapping($client);
        abort_unless($mapping, 404);
        $prefix = $mapping->folder . '/' . ($path === '' ? '' : $path . '/');
        $entries = [];
        foreach ($this->documents($client, $path, $publicOnly) as $document) {
            $relative = substr($document->url, strlen($prefix));
            if (str_contains($relative, '/')) {
                $name = explode('/', $relative, 2)[0];
                $key = 'folder:' . $name;
                $entries[$key] ??= ['name' => $name, 'is_dir' => true, 'path' => ltrim($path . '/' . $name, '/'), 'size' => null];
            } else {
                $entries['file:' . $document->id] = [
                    'name' => basename($document->name), 'is_dir' => false,
                    'path' => ltrim($path . '/' . $relative, '/'),
                    'id' => $document->hashed_id, 'hash' => $document->hash,
                    'size' => (int) $document->size, 'is_public' => (bool) $document->is_public,
                    'updated_at' => $document->updated_at,
                ];
            }
        }
        usort($entries, fn ($a, $b) => $b['is_dir'] <=> $a['is_dir'] ?: strnatcasecmp($a['name'], $b['name']));
        return ['path' => $path, 'folder' => $mapping->folder, 'entries' => array_values($entries)];
    }

    public function archive(Client $client, string $path, bool $publicOnly)
    {
        $this->files->sync($client, true);
        $documents = $this->documents($client, $path, $publicOnly);
        abort_if($documents->isEmpty(), 404, 'There are no downloadable files in this folder.');
        abort_if($documents->sum('size') > 1024 * 1024 * 1024, 422, 'Download a smaller folder (maximum 1 GB per ZIP).');
        $mapping = $this->files->mapping($client);
        $prefix = $mapping->folder . '/' . ($path === '' ? '' : $path . '/');
        $archive = tempnam(sys_get_temp_dir(), 'integratecore-zip-');
        $zip = new \ZipArchive();
        $temporary = [];
        $remaining = 1024 * 1024 * 1024;
        try {
            if ($zip->open($archive, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('The document archive could not be created.');
            }
            foreach ($documents as $document) {
                $file = $this->storage->temporaryPath($this->files->path($document), $remaining);
                $remaining -= filesize($file);
                $temporary[] = $file;
                if (!$zip->addFile($file, substr($document->url, strlen($prefix)))) {
                    throw new \RuntimeException('A document could not be added to the archive.');
                }
            }
            if (!$zip->close()) {
                throw new \RuntimeException('The document archive could not be completed.');
            }
        } catch (\Throwable $e) {
            try {
                @$zip->close();
            } catch (\Throwable) {
                // A failed close may already have invalidated the ZIP handle.
            }
            @unlink($archive);
            throw $e;
        } finally {
            foreach ($temporary as $file) {
                @unlink($file);
            }
        }
        $name = preg_replace('/[^\pL\pN _.-]/u', '_', $path === '' ? $mapping->folder : basename($path));
        return response()->download($archive, $name . '.zip', ['Cache-Control' => 'private, no-store'])->setPrivate()->deleteFileAfterSend(true);
    }
}
