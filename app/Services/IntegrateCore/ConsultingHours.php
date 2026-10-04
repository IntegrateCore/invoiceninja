<?php

namespace App\Services\IntegrateCore;

use App\Models\Client;
use App\Models\Company;

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
        if ($this->mobileSlot($client->company) !== $slot || $stored !== '') {
            return $stored;
        }
        if (!array_key_exists('consulting_hours_balance', $client->getAttributes())) {
            return '';
        }
        return rtrim(rtrim(number_format((float) $client->consulting_hours_balance, 6, '.', ''), '0'), '.');
    }

    public function stripComputedInput(array $input, Company $company): array
    {
        if ($slot = $this->mobileSlot($company)) {
            unset($input["custom_value{$slot}"]);
        }
        return $input;
    }
}
