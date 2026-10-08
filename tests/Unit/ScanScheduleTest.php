<?php

namespace D3Creative\Sentinel\Tests\Unit;

use Carbon\Carbon;
use D3Creative\Sentinel\Services\ContentFreezeService;
use D3Creative\Sentinel\Services\ScheduleService;
use D3Creative\Sentinel\ServiceProvider;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

/**
 * Scheduled scans are opt-in via SENTINEL_SCAN_SCHEDULE; without one the
 * audit only refreshes on demand or when a scheduled report sends.
 */
class ScanScheduleTest extends TestCase
{
    public function test_scan_schedule_is_off_by_default(): void
    {
        config(['statamic-sentinel.scan.schedule' => null]);

        $this->assertNull((new ScheduleService)->scanCronExpression());
    }

    public function test_presets_and_cron_expressions(): void
    {
        foreach ([
            'daily'       => '0 4 * * *',
            'Weekly'      => '0 4 * * 1',
            '30 2 * * *'  => '30 2 * * *',
            'fortnightly' => null,
            '99 * * * *'  => null,
            ''            => null,
        ] as $value => $expected) {
            config(['statamic-sentinel.scan.schedule' => $value]);

            $this->assertSame($expected, (new ScheduleService)->scanCronExpression(), "'{$value}'");
        }
    }

    /**
     * A hand-edited schedule.json used to throw while the console booted,
     * stopping every artisan command on the host.
     */
    public function test_a_malformed_saved_schedule_does_not_break_the_scheduler(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(ScheduleService::RELATIVE_PATH, json_encode([
            'status_report' => ['enabled' => true, 'recipients' => ['a@b.test'], 'frequency' => 'daily', 'time' => ['09:00'], 'day_of_week' => 1, 'day_of_month' => 1],
        ]));

        $this->assertNull((new ScheduleService)->cronExpression('status_report'));

        $schedule = new Schedule;
        $this->provider()->registerSchedule($schedule);
        $this->assertCount(2, $schedule->events());
    }

    public function test_tasks_get_short_overlap_expiries_and_ticks_only_run_when_due(): void
    {
        Storage::fake('local');
        config(['statamic-sentinel.scan.schedule' => 'daily']);

        $schedule = new Schedule;
        $this->provider()->registerSchedule($schedule);

        $expiries = [];
        foreach ($schedule->events() as $event) {
            $expiries[trim(strrchr($event->command, ' '))] = $event->expiresAt;
        }
        $this->assertSame(['sentinel:scan' => 120, 'sentinel:freeze:tick-notifications' => 10, 'sentinel:freeze:tick-activations' => 10], $expiries);

        $tick = collect($schedule->events())->first(fn ($e) => str_contains($e->command, 'tick-activations'));
        $this->assertFalse($tick->filtersPass($this->app));

        Storage::disk('local')->put(ContentFreezeService::CURRENT_PATH, json_encode([
            'id' => 'f', 'status' => ContentFreezeService::STATUS_NOTIFIED,
            'notify_at' => Carbon::now()->subHour()->toIso8601String(), 'freeze_at' => Carbon::now()->subMinute()->toIso8601String(),
        ]));
        $this->assertTrue($tick->filtersPass($this->app));
    }

    protected function provider(): ServiceProvider
    {
        return new ServiceProvider($this->app);
    }
}
