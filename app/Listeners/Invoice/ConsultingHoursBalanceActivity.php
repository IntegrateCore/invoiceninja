<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Listeners\Invoice;

use App\Libraries\MultiDB;
use App\Models\Invoice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ConsultingHoursBalanceActivity implements ShouldQueue
{
    public $delay = 10;

    public $deleteWhenMissingModels = true;

    public function handle($event): void
    {
        MultiDB::setDb($event->company->db);

        $hours = $this->consultingHoursForInvoice($event->invoice);

        if ($hours <= 0) {
            return;
        }

        $event->invoice->client->service()->updateConsultingHoursBalance($hours);
    }

    public function middleware($event): array
    {
        return [(new WithoutOverlapping($event->invoice->client->client_hash))->dontRelease()];
    }

    private function consultingHoursForInvoice(Invoice $invoice): float
    {
        $hours = 0;

        foreach ((array) $invoice->line_items as $item) {
            if (! $this->isConsultingHoursItem($item)) {
                continue;
            }

            $hours += $this->lineItemHours($item);
        }

        return round($hours, 6);
    }

    private function isConsultingHoursItem(object|array $item): bool
    {
        $marker = strtolower(trim(((string) data_get($item, 'product_key', '')) . ' ' . ((string) data_get($item, 'notes', ''))));
        $custom_marker = strtolower(trim((string) data_get($item, 'custom_value2', '')));

        return str_contains($marker, 'consulting-hours')
            || str_contains($marker, 'consulting hours')
            || str_contains($marker, 'consulting_hours')
            || str_contains($marker, 'time left')
            || str_contains($marker, 'hour pack')
            || str_contains($custom_marker, 'consulting-hours');
    }

    private function lineItemHours(object|array $item): float
    {
        $quantity = is_numeric(data_get($item, 'quantity')) ? (float) data_get($item, 'quantity') : 0;
        $per_unit = is_numeric(data_get($item, 'custom_value1')) ? (float) data_get($item, 'custom_value1') : 1;

        return $quantity * $per_unit;
    }
}
