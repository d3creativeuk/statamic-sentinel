<?php

namespace D3Creative\Sentinel\Tests\Unit;

use Carbon\Carbon;
use D3Creative\Sentinel\Http\Middleware\AdvanceFreezeState;
use D3Creative\Sentinel\Services\ContentFreezeService;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery;

/**
 * The freeze tick used to run inside every CP request, guests included, so
 * the request that crossed notify_at (maybe the login page) waited for the
 * heads-up email on a sync queue, and every request took the lock even with
 * no freeze scheduled.
 */
class AdvanceFreezeStateTest extends TestCase
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

    public function test_the_tick_runs_after_the_response_not_during_it(): void
    {
        $ticks   = 0;
        $service = Mockery::mock(ContentFreezeService::class);
        $service->shouldReceive('tickIfDue')->andReturnUsing(function () use (&$ticks) {
            $ticks++;
        });
        $this->app->instance(ContentFreezeService::class, $service);
        $this->actingAs(new GenericUser(['id' => 1]));

        $middleware = new AdvanceFreezeState;
        $request    = Request::create('/cp/dashboard');
        $response   = $middleware->handle($request, fn () => new Response('ok'));

        $this->assertSame(0, $ticks);

        $middleware->terminate($request, $response);

        $this->assertSame(1, $ticks);
    }

    public function test_guests_do_not_tick(): void
    {
        $service = Mockery::mock(ContentFreezeService::class);
        $service->shouldReceive('tickIfDue')->never();
        $this->app->instance(ContentFreezeService::class, $service);

        (new AdvanceFreezeState)->terminate(Request::create('/cp/auth/login'), new Response('ok'));

        $this->addToAssertionCount(1);
    }

    public function test_no_lock_is_taken_when_nothing_is_due(): void
    {
        Cache::shouldReceive('lock')->never();

        $service = new ContentFreezeService;

        // No freeze at all.
        $service->tickIfDue();

        // A freeze whose notify time hasn't come.
        Storage::disk('local')->put(ContentFreezeService::CURRENT_PATH, json_encode([
            'id'        => 'f1',
            'status'    => ContentFreezeService::STATUS_SCHEDULED,
            'notify_at' => Carbon::now()->addHour()->toIso8601String(),
            'freeze_at' => Carbon::now()->addHours(2)->toIso8601String(),
        ]));
        $service->tickIfDue();

        $this->assertFalse($service->hasDueTransition());
    }

    public function test_due_transitions_are_detected(): void
    {
        $service = new ContentFreezeService;

        foreach ([
            [ContentFreezeService::STATUS_SCHEDULED, '-1 minute', '+1 hour', true],
            [ContentFreezeService::STATUS_NOTIFIED, '-1 hour', '+1 minute', false],
            [ContentFreezeService::STATUS_NOTIFIED, '-2 hours', '-1 minute', true],
            [ContentFreezeService::STATUS_ACTIVE, '-2 hours', '-1 hour', false],
        ] as [$status, $notify, $freeze, $due]) {
            Storage::disk('local')->put(ContentFreezeService::CURRENT_PATH, json_encode([
                'id'        => 'f1',
                'status'    => $status,
                'notify_at' => Carbon::parse($notify)->toIso8601String(),
                'freeze_at' => Carbon::parse($freeze)->toIso8601String(),
            ]));

            $this->assertSame($due, $service->hasDueTransition(), "{$status} {$notify} {$freeze}");
        }
    }
}
