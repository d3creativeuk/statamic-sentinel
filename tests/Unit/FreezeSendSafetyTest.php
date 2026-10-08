<?php

namespace D3Creative\Sentinel\Tests\Unit;

use Carbon\Carbon;
use D3Creative\Sentinel\Mail\FreezeCompletionMail;
use D3Creative\Sentinel\Mail\FreezeNotificationMail;
use D3Creative\Sentinel\Services\ContentFreezeService;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Mockery;

/**
 * Each freeze email goes to clients at most once per transition, even when
 * storage won't save, the lock expires mid-send or the mailer fails.
 *
 * @see ContentFreezeService::markNotified()
 * @see ContentFreezeService::completeLocked()
 */
class FreezeSendSafetyTest extends TestCase
{
    protected string $root;

    protected int $sends = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        $this->root = sys_get_temp_dir() . '/sentinel-freeze-' . uniqid();
        File::ensureDirectoryExists($this->root);
        $this->useDisk();
        $this->sends = 0;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * Storage the ticking process can read but not write: the heads-up went
     * out, the status didn't save, and every tick sent it again.
     */
    public function test_an_unsaveable_freeze_sends_no_heads_up(): void
    {
        Mail::fake();
        Log::spy();
        $this->storeFreeze();
        $this->useDisk(writesFail: true);

        $service = new ContentFreezeService;
        for ($i = 0; $i < 5; $i++) {
            $service->tickNotifications();
            $service->tickIfDue();
        }

        Mail::assertNothingQueued();
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, "so it wasn't sent"));
        $this->assertSame(ContentFreezeService::STATUS_SCHEDULED, $service->current()['status']);
    }

    /**
     * The lock used to expire after 60 seconds, as long as one SMTP operation
     * may take, so a slow sync send let the next tick send again.
     */
    public function test_a_send_that_outlives_the_lock_is_not_repeated(): void
    {
        $this->storeFreeze();

        $ticked = false;
        $this->fakeMailer(function () use (&$ticked) {
            if ($ticked) {
                return;
            }

            // Mid-send: time moves on, the lock is gone, and a CP request ticks.
            $ticked = true;
            Carbon::setTestNow(Carbon::now()->addSeconds(90));
            Cache::lock(ContentFreezeService::LOCK_NAME)->forceRelease();
            (new ContentFreezeService)->tickIfDue();
        });

        (new ContentFreezeService)->tickNotifications();

        $this->assertSame(1, $this->sends);
        $this->assertSame(ContentFreezeService::STATUS_NOTIFIED, (new ContentFreezeService)->current()['status']);
        $this->assertArrayNotHasKey('notify_claimed_at', (new ContentFreezeService)->current());
    }

    public function test_a_fresh_claim_holds_back_other_ticks_and_a_stale_one_does_not(): void
    {
        Mail::fake();

        $this->storeFreeze(['notify_claimed_at' => Carbon::now()->subSeconds(30)->toIso8601String()]);
        $service = new ContentFreezeService;
        $this->assertFalse($service->hasDueTransition());
        $this->assertSame(0, $service->tickNotifications());
        Mail::assertNothingQueued();

        // A claim left by a process that died mid-send is retried once stale.
        $this->storeFreeze(['notify_claimed_at' => Carbon::now()->subSeconds(ContentFreezeService::NOTIFY_CLAIM_SECONDS + 1)->toIso8601String()]);
        $this->assertTrue($service->hasDueTransition());
        $this->assertSame(1, $service->tickNotifications());
        Mail::assertQueued(FreezeNotificationMail::class, 1);
    }

    /**
     * A failing mailer used to be retried on every CP request (thumbnails,
     * XHR, Inertia). Now once a minute.
     */
    public function test_a_failed_send_waits_before_retrying(): void
    {
        $this->storeFreeze();
        $this->fakeMailer(fn () => throw new \RuntimeException('Connection refused'));

        $service = new ContentFreezeService;
        for ($i = 0; $i < 25; $i++) {
            $service->tickIfDue();
        }

        $this->assertSame(1, $this->sends);
        $this->assertNotEmpty($service->current()['notify_retry_at']);
        $this->assertSame(ContentFreezeService::STATUS_SCHEDULED, $service->current()['status']);

        Carbon::setTestNow(Carbon::now()->addSeconds(ContentFreezeService::NOTIFY_RETRY_SECONDS + 1));
        $service->tickIfDue();

        $this->assertSame(2, $this->sends);
    }

    public function test_complete_sends_nothing_when_it_cannot_save_the_completion(): void
    {
        Mail::fake();
        $this->storeFreeze(['status' => ContentFreezeService::STATUS_ACTIVE]);
        $this->useDisk(writesFail: true);

        $result = (new ContentFreezeService)->complete('u1');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not sent', $result['message']);
        Mail::assertNothingQueued();
        $this->assertSame(ContentFreezeService::STATUS_ACTIVE, (new ContentFreezeService)->current()['status']);
    }

    public function test_a_failed_all_clear_puts_the_freeze_back_for_a_retry(): void
    {
        $this->storeFreeze(['status' => ContentFreezeService::STATUS_ACTIVE]);

        $failNext = true;
        $this->fakeMailer(function () use (&$failNext) {
            if ($failNext) {
                $failNext = false;
                throw new \RuntimeException('Connection refused');
            }
        });

        $service = new ContentFreezeService;
        $this->assertFalse($service->complete('u1')['ok']);
        $this->assertSame(ContentFreezeService::STATUS_ACTIVE, $service->current()['status']);

        $this->assertTrue($service->complete('u1')['ok']);
        $this->assertSame(2, $this->sends);
        $this->assertNull($service->current());
        $this->assertSame('freeze_s', $service->history()[0]['id']);
    }

    public function test_schedule_waits_for_the_lock_like_every_other_state_change(): void
    {
        $service = new class extends ContentFreezeService {
            const LOCK_WAIT_SECONDS = 1;
        };

        $lock = Cache::lock(ContentFreezeService::LOCK_NAME, 60);
        $lock->get();

        $result = $service->schedule(
            Carbon::now()->addHour()->format('Y-m-d H:i'),
            Carbon::now()->addHours(2)->format('Y-m-d H:i'),
            ['editor@example.com']
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('in progress', $result['message']);
        $this->assertNull($service->current());
        $this->assertFalse($service->deleteHistory('anything'));

        $lock->release();
    }

    protected function fakeMailer(callable $onSend): void
    {
        $pending = Mockery::mock();
        $pending->shouldReceive('queue')->andReturnUsing(function () use ($onSend) {
            $this->sends++;
            $onSend();
        });
        Mail::shouldReceive('to')->andReturn($pending);
    }

    protected function storeFreeze(array $overrides = []): void
    {
        Storage::disk('local')->put(ContentFreezeService::CURRENT_PATH, json_encode($overrides + [
            'id'         => 'freeze_s',
            'status'     => ContentFreezeService::STATUS_SCHEDULED,
            'notify_at'  => Carbon::now()->subMinute()->toIso8601String(),
            'freeze_at'  => Carbon::now()->addHour()->toIso8601String(),
            'recipients' => ['editor@example.com'],
        ]));
    }

    /**
     * A real local disk; with $writesFail, writes to the freeze record fail
     * the way a read-only directory makes Laravel's put() return false.
     */
    protected function useDisk(bool $writesFail = false): void
    {
        $base = Storage::build(['driver' => 'local', 'root' => $this->root]);

        Storage::set('local', new class($base->getDriver(), $base->getAdapter(), $base->getConfig(), $writesFail) extends FilesystemAdapter {
            public function __construct($driver, $adapter, array $config, protected bool $writesFail)
            {
                parent::__construct($driver, $adapter, $config);
            }

            public function put($path, $contents, $options = [])
            {
                if ($this->writesFail && str_starts_with($path, ContentFreezeService::CURRENT_PATH)) {
                    return false;
                }

                return parent::put($path, $contents, $options);
            }
        });
    }
}
