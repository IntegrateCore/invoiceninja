<?php

namespace App\Console\Commands;

use App\Models\ClientFileFolder;
use App\Services\IntegrateCore\ClientFiles;
use Illuminate\Console\Command;

class SyncClientFileLibrary extends Command
{
    protected $signature = 'integratecore:client-files {--client= : Numeric client ID} {--migrate : Copy and verify existing documents; retain originals} {--dry-run : Report migration without changing documents}';
    protected $description = 'Synchronize client documents with the IntegrateCore file library';

    public function handle(ClientFiles $files): int
    {
        if (!config('integratecore.enabled')) {
            $this->error('The file library is disabled.');
            return self::FAILURE;
        }
        $failed = false;
        foreach (ClientFileFolder::when($this->option('client'), fn ($q, $id) => $q->where('client_id', $id))->with('client')->get() as $mapping) {
            if (!$mapping->client || $mapping->client->is_deleted) {
                continue;
            }
            try {
                if ($this->option('migrate') || $this->option('dry-run')) {
                    foreach ($files->migrate($mapping->client, (bool) $this->option('dry-run')) as $entry) {
                        $this->line(json_encode($entry));
                    }
                }
                if (!$this->option('dry-run')) {
                    $files->sync($mapping->client, true);
                }
                $this->info('Client ' . $mapping->client_id . ': complete');
            } catch (\Throwable $e) {
                report($e);
                $this->error('Client ' . $mapping->client_id . ': failed; previous records and original files retained.');
                $failed = true;
            }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
