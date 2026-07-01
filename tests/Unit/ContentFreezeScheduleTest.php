<?php

namespace D3Creative\Sentinel\Tests\Unit;

use Carbon\Carbon;
use D3Creative\Sentinel\Services\ContentFreezeService;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

/**
 * Covers the "Freeze ends at" + "Expected duration" additions to the content
 * freeze schedule: an end time before the start is rejected, expected duration
 * normalises by unit, and the notification email's derived strings degrade
 * gracefully for legacy records that predate the new fields.
 */
class ContentFreezeScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function service(): ContentFreezeService
    {
        return new ContentFreezeService;
    }

    /** Valid notify/start/end strings in the configured timezone. */
    private function times(): array
    {
        $tz = $this->service()->timezone();

        return [
            'notify'  => Carbon::now($tz)->addMinutes(10)->format('Y-m-d H:i'),
            'start'   => Carbon::now($tz)->addHour()->format('Y-m-d H:i'),
            'end'     => Carbon::now($tz)->addHours(4)->format('Y-m-d H:i'), // 3h after start
            'endBad'  => Carbon::now($tz)->addMinutes(30)->format('Y-m-d H:i'), // before start
        ];
    }

    public function test_freeze_end_before_start_is_rejected(): void
    {
        $t = $this->times();

        $result = $this->service()->schedule($t['notify'], $t['start'], ['a@b.com'], null, [
            'freeze_ends_at' => $t['endBad'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('End time must be later than start time', $result['message']);
    }

    public function test_valid_end_and_expected_duration_are_stored(): void
    {
        $t = $this->times();

        $result = $this->service()->schedule($t['notify'], $t['start'], ['a@b.com'], null, [
            'freeze_ends_at'         => $t['end'],
            'expected_duration'      => '2',
            'expected_duration_unit' => 'hours',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertNotNull($result['freeze']['freeze_ends_at']);
        $this->assertSame(120, $result['freeze']['expected_duration_minutes']);
    }

    public function test_expected_duration_normalises_by_unit(): void
    {
        $svc = $this->service();
        $t   = $this->times();

        foreach ([['30', 'minutes', 30], ['2', 'hours', 120], ['1', 'days', 1440]] as [$n, $unit, $expected]) {
            $result = $svc->schedule($t['notify'], $t['start'], ['a@b.com'], null, [
                'expected_duration'      => $n,
                'expected_duration_unit' => $unit,
            ]);

            $this->assertTrue($result['ok'], "unit {$unit}");
            $this->assertSame($expected, $result['freeze']['expected_duration_minutes'], "unit {$unit}");

            $svc->cancel(); // clear the current freeze so the next schedule() is allowed
        }
    }

    public function test_invalid_expected_duration_is_rejected(): void
    {
        $svc = $this->service();
        $t   = $this->times();

        foreach ([['0', 'minutes'], ['abc', 'minutes'], ['5', 'weeks']] as [$n, $unit]) {
            $result = $svc->schedule($t['notify'], $t['start'], ['a@b.com'], null, [
                'expected_duration'      => $n,
                'expected_duration_unit' => $unit,
            ]);

            $this->assertFalse($result['ok'], "{$n}/{$unit} should be rejected");
        }
    }

    public function test_omitting_the_new_fields_still_schedules(): void
    {
        $t = $this->times();

        $result = $this->service()->schedule($t['notify'], $t['start'], ['a@b.com']);

        $this->assertTrue($result['ok']);
        $this->assertNull($result['freeze']['freeze_ends_at']);
        $this->assertNull($result['freeze']['expected_duration_minutes']);
    }

    public function test_format_time_omits_timezone_letters_when_freeze_tz_unset(): void
    {
        config(['statamic-sentinel.freeze.timezone' => null, 'app.timezone' => 'UTC']);

        $this->assertSame('4 Jul 2026, 08:00', $this->service()->formatTime('2026-07-04T08:00:00Z'));
    }

    public function test_format_time_shows_letters_when_freeze_tz_set(): void
    {
        // Explicit zone that differs from the server -> dual time, both with letters.
        config(['statamic-sentinel.freeze.timezone' => 'Europe/London', 'app.timezone' => 'UTC']);

        $out = $this->service()->formatTime('2026-07-04T08:00:00Z');

        $this->assertStringContainsString('BST', $out);   // 09:00 BST
        $this->assertStringContainsString('UTC', $out);   // 08:00 UTC
        $this->assertStringContainsString(' / ', $out);
    }

    public function test_format_time_shows_letters_when_freeze_tz_equals_app_tz(): void
    {
        // Explicitly set (even to the app tz) still shows letters, single time.
        config(['statamic-sentinel.freeze.timezone' => 'UTC', 'app.timezone' => 'UTC']);

        $this->assertSame('4 Jul 2026, 08:00 UTC', $this->service()->formatTime('2026-07-04T08:00:00Z'));
    }

    public function test_format_duration_is_human_friendly(): void
    {
        $svc = $this->service();

        $this->assertSame('30 minutes', $svc->formatDuration(30));
        $this->assertSame('3 hours', $svc->formatDuration(180));
        $this->assertSame('1 hour 30 minutes', $svc->formatDuration(90));
        $this->assertSame('', $svc->formatDuration(0));
    }

    public function test_draft_record_reflects_raw_form_input(): void
    {
        $svc = $this->service();

        $freeze = $svc->draftRecord([
            'freeze_at'              => '2026-07-04 15:40',
            'freeze_ends_at'         => '2026-07-04 17:00',
            'expected_duration'      => '45',
            'expected_duration_unit' => 'minutes',
        ]);

        $this->assertNotNull($freeze['freeze_ends_at']);
        $this->assertSame(45, $freeze['expected_duration_minutes']);

        // The email's derived strings should match the entered window + duration.
        $extras = $svc->notificationExtras($freeze);
        $this->assertSame('1 hour 20 minutes', $extras['window_text']);
        $this->assertSame('45 minutes', $extras['expected_text']);
    }

    public function test_draft_record_degrades_for_blank_and_bad_input(): void
    {
        $svc = $this->service();

        // Blank end/duration -> no window/expected (preview shows no sentence).
        $blank = $svc->draftRecord([
            'freeze_at'         => '2026-07-04 15:40',
            'freeze_ends_at'    => '',
            'expected_duration' => '',
        ]);
        $this->assertNull($blank['freeze_ends_at']);
        $this->assertNull($blank['expected_duration_minutes']);

        // Unparseable start still yields a renderable record (falls back to now).
        $bad = $svc->draftRecord(['freeze_at' => 'not a date']);
        $this->assertNotNull($bad['freeze_at']);
    }

    public function test_notification_extras_degrade_for_legacy_records(): void
    {
        $svc = $this->service();
        $now = Carbon::now();

        $full = $svc->notificationExtras([
            'freeze_at'                 => $now->toIso8601String(),
            'freeze_ends_at'            => $now->copy()->addHours(3)->toIso8601String(),
            'expected_duration_minutes' => 30,
        ]);

        $this->assertSame('3 hours', $full['window_text']);
        $this->assertSame('30 minutes', $full['expected_text']);
        $this->assertNotNull($full['ends_display']);

        // Record written before these fields existed - no crash, all null.
        $legacy = $svc->notificationExtras(['freeze_at' => $now->toIso8601String()]);

        $this->assertNull($legacy['window_text']);
        $this->assertNull($legacy['expected_text']);
        $this->assertNull($legacy['ends_display']);
    }
}
