<?php

namespace D3Creative\Sentinel\Tests\Unit;

use Carbon\Carbon;
use D3Creative\Sentinel\Console\Commands\FreezeTickNotificationsCommand;
use D3Creative\Sentinel\Mail\FreezeCompletionMail;
use D3Creative\Sentinel\Mail\FreezeNotificationMail;
use D3Creative\Sentinel\Services\ContentFreezeService;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Mockery;

/**
 * Every freeze state change runs under one lock, so the cron ticks, the
 * per-request middleware tick and Complete / Cancel can't interleave.
 *
 * @see ContentFreezeService::withFreezeLock()
 */
class ContentFreezeRaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * The production race: Cancel (or Complete) lands while the heads-up email
     * is sending, and the tick then wrote its stale copy back as `notified`.
     * Simulated here by removing the record from inside the send, as a
     * process on a lock-less cache store could.
     */
    public function test_a_freeze_removed_during_the_heads_up_send_is_not_revived(): void
    {
        $this->storeDueFreeze();

        $pending = Mockery::mock();
        $pending->shouldReceive('queue')->once()->andReturnUsing(function () {
            Storage::disk('local')->delete(ContentFreezeService::CURRENT_PATH);
        });
        Mail::shouldReceive('to')->andReturn($pending);

        (new ContentFreezeService)->tickNotifications();

        $this->assertNull((new ContentFreezeService)->current());
    }

    public function test_ticks_skip_while_another_process_holds_the_lock(): void
    {
        Mail::fake();
        $this->storeDueFreeze();
        $service = new ContentFreezeService;

        $lock = Cache::lock(ContentFreezeService::LOCK_NAME, 60);
        $this->assertTrue($lock->get());

        // Both entry points: the scheduled command and the middleware tick.
        (new FreezeTickNotificationsCommand)->handle($service);
        $service->tickIfDue();

        Mail::assertNothingQueued();
        $this->assertSame(ContentFreezeService::STATUS_SCHEDULED, $service->current()['status']);

        $lock->release();

        $this->assertSame(1, $service->tickNotifications());
        Mail::assertQueued(FreezeNotificationMail::class, 1);
    }

    public function test_complete_reports_busy_instead_of_racing_an_in_flight_tick(): void
    {
        Mail::fake();
        $this->storeDueFreeze();

        $service = new class extends ContentFreezeService {
            const LOCK_WAIT_SECONDS = 1;
        };

        $lock = Cache::lock(ContentFreezeService::LOCK_NAME, 60);
        $lock->get();

        $result = $service->complete('someone');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('in progress', $result['message']);
        $this->assertNotNull($service->current());
        Mail::assertNothingQueued();

        $lock->release();
    }

    public function test_completing_twice_sends_one_all_clear(): void
    {
        Mail::fake();
        $this->storeDueFreeze(ContentFreezeService::STATUS_ACTIVE);
        $service = new ContentFreezeService;

        $this->assertTrue($service->complete('first')['ok']);
        $second = $service->complete('second');

        $this->assertFalse($second['ok']);
        $this->assertSame('No update to complete.', $second['message']);
        $this->assertCount(1, $service->history());
        Mail::assertQueued(FreezeCompletionMail::class, 1);
    }

    protected function storeDueFreeze(string $status = ContentFreezeService::STATUS_SCHEDULED): void
    {
        Storage::disk('local')->put(ContentFreezeService::CURRENT_PATH, json_encode([
            'id'         => 'freeze123',
            'status'     => $status,
            'notify_at'  => Carbon::now()->subMinute()->toIso8601String(),
            'freeze_at'  => Carbon::now()->addHour()->toIso8601String(),
            'recipients' => ['editor@example.com'],
        ]));
    }
}
