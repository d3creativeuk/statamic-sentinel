<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Services\AuditService;
use D3Creative\Sentinel\Support\ManualScan;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Mockery;

/**
 * `?d3_refresh=1` used to run a full scan for anyone who could load the
 * dashboard, from any link, as often as they liked.
 *
 * @see ManualScan
 */
class ManualScanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        FakeManualScan::$canView = true;
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_requests_without_the_parameter_render_normally(): void
    {
        (new FakeManualScan)->handle(Request::create('/cp/dashboard'), $this->audit(0));

        $this->addToAssertionCount(1);
    }

    public function test_a_valid_request_scans_once_and_redirects_without_the_parameter(): void
    {
        $redirect = $this->handle('/cp/dashboard?d3_refresh=good-token&tab=1', $this->audit(1));

        $this->assertStringNotContainsString('d3_refresh', $redirect);
        $this->assertStringContainsString('tab=1', $redirect);
    }

    public function test_a_link_without_the_session_token_does_not_scan(): void
    {
        $this->handle('/cp/dashboard?d3_refresh=1', $this->audit(0));
        $this->handle('/cp/dashboard?d3_refresh=', $this->audit(0));
    }

    public function test_users_without_sentinel_access_do_not_scan(): void
    {
        FakeManualScan::$canView = false;

        $this->handle('/cp/dashboard?d3_refresh=good-token', $this->audit(0));
    }

    public function test_a_second_scan_within_the_cooldown_is_skipped(): void
    {
        $this->handle('/cp/dashboard?d3_refresh=good-token', $this->audit(1));
        $this->handle('/cp/dashboard?d3_refresh=good-token', $this->audit(0));
    }

    public function test_a_scan_already_running_is_not_started_again(): void
    {
        $lock = Cache::lock(ManualScan::LOCK, 60);
        $this->assertTrue($lock->get());

        $this->handle('/cp/dashboard?d3_refresh=good-token', $this->audit(0));

        $lock->release();
    }

    protected function audit(int $scans): AuditService
    {
        $audit = Mockery::mock(AuditService::class);
        $audit->shouldReceive('refresh')->times($scans);

        return $audit;
    }

    protected function handle(string $uri, AuditService $audit): string
    {
        try {
            (new FakeManualScan)->handle(Request::create($uri), $audit);
        } catch (HttpResponseException $e) {
            $url = $e->getResponse()->getTargetUrl();

            // Every handled request redirects to the URL without the parameter.
            $this->assertStringNotContainsString('d3_refresh', $url);

            return $url;
        }

        $this->fail('Expected a redirect.');
    }
}

class FakeManualScan extends ManualScan
{
    public static bool $canView = true;

    public static function userCanView(): bool
    {
        return static::$canView;
    }

    public static function token(): string
    {
        return 'good-token';
    }
}
