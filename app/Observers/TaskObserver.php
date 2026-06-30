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

namespace App\Observers;

use App\Jobs\Util\WebhookHandler;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Webhook;

class TaskObserver
{
    public $afterCommit = true;

    /**
     * Handle the task "created" event.
     *
     * @param Task $task
     * @return void
     */
    public function created(Task $task)
    {
        $this->syncConsultingHoursBalance($task);

        $subscriptions = Webhook::where('company_id', $task->company_id)
                        ->where('event_id', Webhook::EVENT_CREATE_TASK)
                        ->exists();

        if ($subscriptions) {
            WebhookHandler::dispatch(Webhook::EVENT_CREATE_TASK, $task, $task->company)->delay(0);
        }
    }

    /**
     * Handle the task "updated" event.
     *
     * @param Task $task
     * @return void
     */
    public function updated(Task $task)
    {
        $this->syncConsultingHoursBalance($task);

        $event = Webhook::EVENT_UPDATE_TASK;

        if ($task->getOriginal('deleted_at') && !$task->deleted_at) {
            $event = Webhook::EVENT_RESTORE_TASK;
        }

        if ($task->is_deleted) {
            $event = Webhook::EVENT_DELETE_TASK;
        }

        $subscriptions = Webhook::where('company_id', $task->company_id)
                                    ->where('event_id', $event)
                                    ->exists();

        if ($subscriptions) {
            WebhookHandler::dispatch($event, $task, $task->company)->delay(0);
        }
    }

    /**
     * Handle the task "deleted" event.
     *
     * @param Task $task
     * @return void
     */
    public function deleted(Task $task)
    {
        if ($task->is_deleted) {
            return;
        }

        $subscriptions = Webhook::where('company_id', $task->company_id)
                        ->where('event_id', Webhook::EVENT_ARCHIVE_TASK)
                        ->exists();

        if ($subscriptions) {
            WebhookHandler::dispatch(Webhook::EVENT_ARCHIVE_TASK, $task, $task->company)->delay(0);
        }

    }

    /**
     * Handle the task "restored" event.
     *
     * @param Task $task
     * @return void
     */
    public function restored(Task $task)
    {
        //
    }

    /**
     * Handle the task "force deleted" event.
     *
     * @param Task $task
     * @return void
     */
    public function forceDeleted(Task $task)
    {
        //
    }

    private function syncConsultingHoursBalance(Task $task): void
    {
        if (! $task->client) {
            return;
        }

        $desired = $this->shouldConsumeConsultingHours($task)
            ? round($this->billableHours($task), 6)
            : 0;

        $previous = round((float) $task->getOriginal('consulting_hours_consumed', 0), 6);

        if (abs($desired - $previous) < 0.000001) {
            return;
        }

        $task->client->service()->updateConsultingHoursBalance(round($previous - $desired, 6));

        $task->forceFill(['consulting_hours_consumed' => $desired])->saveQuietly();
    }

    private function billableHours(Task $task): float
    {
        return round($task->calcDuration(true) / 3600, 6);
    }

    private function shouldConsumeConsultingHours(Task $task): bool
    {
        if (! $task->status) {
            return false;
        }

        $statusName = strtolower(trim((string) $task->status->name));

        if (
            str_contains($statusName, 'done') ||
            str_contains($statusName, 'complete') ||
            str_contains($statusName, 'finished') ||
            str_contains($statusName, 'closed')
        ) {
            return true;
        }

        $maxStatusOrder = TaskStatus::query()
            ->where('company_id', $task->company_id)
            ->max('status_order');

        return $maxStatusOrder !== null
            && $task->status_order !== null
            && (int) $task->status_order === (int) $maxStatusOrder;
    }
}
