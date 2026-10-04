<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\IntegrateCore\ConsultingHours;
use App\Services\IntegrateCore\ConsultingHoursAlertSender;
use Illuminate\Console\Command;

class ConsultingHoursAlerts extends Command
{
    protected $signature = 'integratecore:consulting-hours-alerts';
    protected $description = 'Send one low-hours email per funded client until their balance is refilled above two hours';

    public function handle(ConsultingHours $hours, ConsultingHoursAlertSender $sender): int
    {
        if (!config('integratecore.consulting_hours_alerts_enabled', false)) {
            $this->info('Consulting hours alerts are disabled.');
            return self::SUCCESS;
        }
        $failed = false;
        Client::query()->withoutEagerLoads()->where('is_deleted', false)
            ->where(function ($query) {
                $query->where(function ($pending) {
                    $pending->where('consulting_hours_funded', true)
                        ->where('consulting_hours_balance', '<=', ConsultingHours::THRESHOLD)
                        ->whereNull('consulting_hours_alerted_at');
                })->orWhere(function ($refilled) {
                    $refilled->where('consulting_hours_balance', '>', ConsultingHours::THRESHOLD)
                        ->whereNotNull('consulting_hours_alerted_at');
                })->orWhere(function ($newlyFunded) {
                    $newlyFunded->where('consulting_hours_funded', false)->where('consulting_hours_balance', '>', 0);
                });
            })->select('id')->chunkById(100, function ($clients) use ($hours, $sender, &$failed) {
                foreach ($clients as $candidate) {
                    try {
                        // The lock covers the send and dedupe marker, preventing overlapping runners.
                        $candidate->getConnection()->transaction(function () use ($candidate, $hours, $sender) {
                            $client = Client::query()->withoutEagerLoads()->lockForUpdate()->find($candidate->id);
                            if (!$client) {
                                return;
                            }
                            $hours->track($client);
                            if ($hours->needsAlert($client)) {
                                $sender->send($client);
                                $client->consulting_hours_alerted_at = now();
                            }
                            if ($client->isDirty()) {
                                $client->saveQuietly();
                            }
                        });
                    } catch (\Throwable $error) {
                        $failed = true;
                        report($error);
                        $this->error("Consulting hours alert failed for client {$candidate->id}; it will be retried.");
                    }
                }
            });
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
