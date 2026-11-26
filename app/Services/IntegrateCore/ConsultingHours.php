<?php

namespace App\Services\IntegrateCore;

use App\Models\Client;
use App\Models\Company;
use App\Models\Task;

class ConsultingHours
{
    public const THRESHOLD = 2.0;
    public const MOBILE_LABEL = 'Time left (hours)';

    /** Called while the client is locked, before its balance is saved. */
    public function track(Client $client, ?float $previous = null): void
    {
        if ((float) $client->consulting_hours_balance > 0 || ($previous !== null && $previous > 0)) {
            $client->consulting_hours_funded = true;
        }
        if ((float) $client->consulting_hours_balance > self::THRESHOLD) {
            $client->consulting_hours_alerted_at = null;
        }
    }

    /**
     * Apply each change in logged billable time once, independent of task status.
     * Read the persisted ledger under lock, not the observer's possibly stale snapshot.
     */
    public function syncTask(Task $task, ?int $previousClientId = null): void
    {
        $task->getConnection()->transaction(function () use ($task, $previousClientId) {
            $current = $task->newQuery()->where('company_id', $task->company_id)
                ->lockForUpdate()->find($task->id);

            if (!$current || $current->is_deleted) {
                return;
            }

            $previous = round((float) $current->consulting_hours_consumed, 6);
            $clientId = $current->client_id ? (int) $current->client_id : null;
            // Older entries did not record the charged client. Preserve that debit on migration.
            $chargedId = $previous > 0
                ? (int) ($current->consulting_hours_client_id ?? $previousClientId ?? $task->getOriginal('client_id') ?? $clientId)
                : null;
            $desired = $clientId ? $this->loggedBillableHours($current) : 0.0;
            $desiredId = $desired > 0 ? $clientId : null;
            $deltas = [];

            if ($chargedId) {
                $deltas[$chargedId] = $previous;
            }
            if ($desiredId) {
                $deltas[$desiredId] = round(($deltas[$desiredId] ?? 0) - $desired, 6);
            }

            ksort($deltas, SORT_NUMERIC);
            foreach ($deltas as $id => $delta) {
                if ($delta === 0.0) {
                    continue;
                }
                $client = Client::on($task->getConnectionName())->withTrashed()->withoutEagerLoads()
                    ->where('company_id', $current->company_id)->lockForUpdate()->find($id);

                if (!$client) {
                    // Keep the ledger and all balances unchanged if a referenced client is gone.
                    // Throwing here also rolls back an adjustment already made for the other client.
                    throw new \RuntimeException('Could not reconcile the consulting-hours client.');
                }

                $balance = (float) $client->consulting_hours_balance;
                $client->consulting_hours_balance = round($balance + $delta, 6);
                $this->track($client, $balance);
                $client->saveQuietly();
            }

            if ($previous !== $desired || $current->consulting_hours_client_id != $desiredId) {
                $current->newQuery()->whereKey($current->id)->toBase()->update([
                    'consulting_hours_consumed' => $desired,
                    'consulting_hours_client_id' => $desiredId,
                ]);
            }
        }, 2);
    }

    /** Count completed time entries only; a running timer is counted when saved with an end time. */
    private function loggedBillableHours(Task $task): float
    {
        $seconds = 0;
        $logs = json_decode($task->time_log ?? '', true);

        foreach (is_array($logs) ? $logs : [] as $part) {
            if (!is_array($part) || !is_numeric($part[0] ?? null) || !is_numeric($part[1] ?? null)
                || !$part[1] || (isset($part[3]) && !$part[3])) {
                continue;
            }
            $seconds += max($part[1] - $part[0], 0);
        }

        return round(round($seconds) / 3600, 6);
    }

    /** Reconcile a normal client edit after commit using the current locked row. */
    public function reconcile(Client $client): void
    {
        $client->getConnection()->transaction(function () use ($client) {
            $current = Client::withTrashed()->withoutEagerLoads()->lockForUpdate()->find($client->id);
            if (!$current) {
                return;
            }
            $this->track($current);
            if ($current->isDirty()) {
                $current->saveQuietly();
            }
        });
    }

    public function needsAlert(Client $client): bool
    {
        return (bool) $client->consulting_hours_funded
            && (float) $client->consulting_hours_balance <= self::THRESHOLD
            && !$client->consulting_hours_alerted_at
            && !$client->is_deleted && !$client->deleted_at;
    }

    public function mobileSlot(Company $company): ?int
    {
        $slot = (int) $company->consulting_hours_custom_field;
        return $slot >= 1 && $slot <= 4
            && data_get($company->custom_fields, "client{$slot}") === self::MOBILE_LABEL
                ? $slot : null;
    }

    /** Never expose an excluded balance or replace actual custom-field data. */
    public function mobileValue(Client $client, int $slot): string
    {
        $stored = (string) ($client->getAttribute("custom_value{$slot}") ?? '');
        if (!array_key_exists('consulting_hours_balance', $client->getAttributes())) {
            return $stored;
        }
        if ($this->mobileSlot($client->company) !== $slot || $stored !== '') {
            return $stored;
        }
        return number_format((float) $client->consulting_hours_balance, 2, '.', '');
    }

    public function stripComputedInput(array $input, Company $company): array
    {
        if ($slot = $this->mobileSlot($company)) {
            unset($input["custom_value{$slot}"]);
        }
        return $input;
    }
}
