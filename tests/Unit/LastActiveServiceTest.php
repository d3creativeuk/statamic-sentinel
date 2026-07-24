<?php

namespace D3Creative\Sentinel\Tests\Unit;

use Carbon\Carbon;
use D3Creative\Sentinel\Services\LastActiveService;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

/**
 * Coverage for the CP user activity store behind the utility's Users tab:
 * recording last-active timestamps, pruning stale entries, and the online
 * window check.
 *
 * @see LastActiveService
 */
class LastActiveServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_touch_records_and_updates_a_users_timestamp(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-24 12:00:00'));

        (new LastActiveService)->touch('u1');
        $this->assertSame(Carbon::now()->toIso8601String(), (new LastActiveService)->all()['u1']);

        // A later request updates the same user's timestamp in place.
        Carbon::setTestNow(Carbon::parse('2026-07-24 12:05:00'));
        (new LastActiveService)->touch('u1');

        $all = (new LastActiveService)->all();
        $this->assertCount(1, $all);
        $this->assertSame(Carbon::now()->toIso8601String(), $all['u1']);
    }

    public function test_all_is_empty_when_nothing_is_stored(): void
    {
        $this->assertSame([], (new LastActiveService)->all());
    }

    public function test_touch_prunes_entries_older_than_the_retention_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-24 12:00:00'));

        // Seed a stale entry (past retention) and a recent one.
        Storage::disk('local')->put(LastActiveService::RELATIVE_PATH, json_encode([
            'stale'  => Carbon::now()->subDays(LastActiveService::RETENTION_DAYS + 5)->toIso8601String(),
            'recent' => Carbon::now()->subDay()->toIso8601String(),
        ]));

        // A touch triggers a prune on write.
        (new LastActiveService)->touch('fresh');

        $all = (new LastActiveService)->all();
        $this->assertArrayNotHasKey('stale', $all);
        $this->assertArrayHasKey('recent', $all);
        $this->assertArrayHasKey('fresh', $all);
    }

    public function test_is_online_is_true_only_within_the_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-24 12:00:00'));

        $this->assertTrue(LastActiveService::isOnline(Carbon::now()->subMinutes(2)->toIso8601String(), 5));
        $this->assertFalse(LastActiveService::isOnline(Carbon::now()->subMinutes(10)->toIso8601String(), 5));
        $this->assertFalse(LastActiveService::isOnline(null, 5));
    }
}
