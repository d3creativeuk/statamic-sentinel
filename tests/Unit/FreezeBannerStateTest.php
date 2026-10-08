<?php

namespace D3Creative\Sentinel\Tests\Unit;

use Carbon\Carbon;
use D3Creative\Sentinel\Http\Controllers\FreezeController;
use D3Creative\Sentinel\Http\Middleware\RecordLastActive;
use D3Creative\Sentinel\Services\ContentFreezeService;
use D3Creative\Sentinel\Services\LastActiveService;
use D3Creative\Sentinel\Support\CpAccess;
use D3Creative\Sentinel\Tests\Support\ActsAsStatamicUser;
use D3Creative\Sentinel\Tests\Support\RegistersViews;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The Statamic 6 banner refresh: the key that says which banner is showing,
 * and the endpoint the CP fetches new markup from when that key changes.
 */
class FreezeBannerStateTest extends TestCase
{
    use ActsAsStatamicUser;
    use RegistersViews;

    public bool $cpAccess = true;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Storage::fake('local');
        $this->registerViews();

        $this->cpAccess = true;
        $test = $this;
        $this->app->instance(CpAccess::class, new class($test) extends CpAccess {
            public function __construct(private $test)
            {
            }

            public function allows(): bool
            {
                return $this->test->cpAccess;
            }
        });
    }

    public function test_the_key_follows_the_banner_that_would_show(): void
    {
        $service = new ContentFreezeService;

        $this->assertSame(['key' => 'none', 'transition_at' => null], $service->bannerState());

        $freezeAt = Carbon::now()->addHour()->toIso8601String();
        $this->writeFreeze(ContentFreezeService::STATUS_SCHEDULED, Carbon::now()->addMinutes(10)->toIso8601String(), $freezeAt);
        $this->assertSame(['key' => 'upcoming:freeze_x', 'transition_at' => $freezeAt], $service->bannerState());

        // Scheduled and notified show the same banner, so the key doesn't change.
        $this->writeFreeze(ContentFreezeService::STATUS_NOTIFIED, Carbon::now()->subMinute()->toIso8601String(), $freezeAt);
        $this->assertSame('upcoming:freeze_x', $service->bannerState()['key']);

        $this->writeFreeze(ContentFreezeService::STATUS_ACTIVE, Carbon::now()->subHours(2)->toIso8601String(), Carbon::now()->subHour()->toIso8601String());
        $this->assertSame(['key' => 'active:freeze_x', 'transition_at' => null], $service->bannerState());

        Storage::disk('local')->delete(ContentFreezeService::CURRENT_PATH);
        Storage::disk('local')->put(ContentFreezeService::HISTORY_PATH, json_encode([
            ['id' => 'freeze_x', 'status' => ContentFreezeService::STATUS_COMPLETE, 'completed_at' => Carbon::now()->subMinute()->toIso8601String()],
        ]));
        $this->assertSame('complete:freeze_x', $service->bannerState()['key']);
    }

    public function test_the_endpoint_is_for_cp_users_only(): void
    {
        $this->cpAccess = false;

        try {
            (new FreezeController)->banner(new ContentFreezeService);
            $this->fail('Expected a 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    /**
     * At freeze_at the scheduler may not have run yet; the endpoint switches
     * the freeze on itself (no email involved) so the amber banner shows.
     */
    public function test_the_endpoint_activates_a_due_freeze_and_returns_its_banner(): void
    {
        $this->actingAsStatamicUser(false);
        $this->writeFreeze(ContentFreezeService::STATUS_NOTIFIED, Carbon::now()->subHour()->toIso8601String(), Carbon::now()->subSecond()->toIso8601String());

        $response = (new FreezeController)->banner(new ContentFreezeService);
        $data     = $response->getData(true);

        $this->assertSame('active:freeze_x', $data['key']);
        $this->assertStringContainsString('Statamic update in progress', $data['html']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(ContentFreezeService::STATUS_ACTIVE, (new ContentFreezeService)->current()['status']);
    }

    public function test_the_endpoint_returns_an_empty_banner_without_a_freeze(): void
    {
        $data = (new FreezeController)->banner(new ContentFreezeService)->getData(true);

        $this->assertSame('none', $data['key']);
        $this->assertSame('', trim($data['html']));
    }

    /**
     * The refresh is the CP checking in, not the user doing anything, so it
     * mustn't keep them "online" on the Users tab.
     */
    public function test_the_banner_refresh_does_not_count_as_activity(): void
    {
        $this->actingAsStatamicUser(false);

        $this->runRecordLastActive('statamic.cp.d3-sentinel.freeze.banner');
        $this->assertSame([], app(LastActiveService::class)->all());

        $this->runRecordLastActive('statamic.cp.dashboard');
        $this->assertArrayHasKey('user-1', app(LastActiveService::class)->all());
    }

    protected function runRecordLastActive(string $routeName): void
    {
        $request = Request::create('/cp/x');
        $route   = (new Route('GET', 'cp/x', fn () => ''))->name($routeName);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        (new RecordLastActive)->handle($request, fn () => response(''));
    }

    protected function writeFreeze(string $status, string $notifyAt, string $freezeAt): void
    {
        Storage::disk('local')->put(ContentFreezeService::CURRENT_PATH, json_encode([
            'id'         => 'freeze_x',
            'status'     => $status,
            'notify_at'  => $notifyAt,
            'freeze_at'  => $freezeAt,
            'recipients' => [],
        ]));
    }
}
