<?php

namespace Tests\Unit;

use App\Console\Kernel;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class IntegrateCoreLibraryScheduleTest extends TestCase
{
    public function test_scheduled_library_refresh_only_syncs_and_never_migrates_original_uploads(): void
    {
        config(['integratecore.enabled' => true]);
        $events = $this->libraryEvents();
        self::assertCount(1, $events);
        self::assertStringEndsWith('integratecore:client-files', $events[0]->command);
        self::assertStringNotContainsString('--migrate', $events[0]->command);
        self::assertSame('* * * * *', $events[0]->expression);
        self::assertTrue($events[0]->withoutOverlapping);
    }

    public function test_disabled_library_has_no_scheduled_refresh(): void
    {
        config(['integratecore.enabled' => false]);
        self::assertSame([], $this->libraryEvents());
    }

    private function libraryEvents(): array
    {
        $schedule = new Schedule();
        $kernel = $this->app->make(Kernel::class);
        (new \ReflectionMethod(Kernel::class, 'schedule'))->invoke($kernel, $schedule);
        return array_values(array_filter($schedule->events(), fn ($event) => str_contains($event->command ?? '', 'integratecore:client-files')));
    }
}
