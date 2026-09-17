<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Services\ScheduleService;
use D3Creative\Sentinel\Tests\TestCase;

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
}
