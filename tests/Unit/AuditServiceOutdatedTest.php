<?php

namespace D3Creative\Sentinel\Tests\Unit;

use Carbon\CarbonImmutable;
use D3Creative\Sentinel\Services\AuditService;
use D3Creative\Sentinel\Tests\TestCase;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Mockery;
use ReflectionMethod;

/**
 * Regression coverage for the fix where a per-request failure in Http::pool()
 * returned an Illuminate\Http\Client\ConnectionException object (not a Response)
 * into the results array. The old guard called ->ok() on it and 500'd the CP.
 *
 * @see AuditService::isOkResponse()
 */
class AuditServiceOutdatedTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * The authoritative regression: isOkResponse() must never call ->ok() on a
     * value that is not a Response. Feeds every value a real pool slot can hold.
     */
    public function test_is_ok_response_only_accepts_a_successful_response(): void
    {
        $service = new AuditService;

        $method = new ReflectionMethod($service, 'isOkResponse');
        $method->setAccessible(true);

        // The exact object that crashed production - must be rejected, not ->ok()'d.
        $this->assertFalse($method->invoke($service, new ConnectionException('timeout')));
        $this->assertFalse($method->invoke($service, null));
        $this->assertFalse($method->invoke($service, new Response(new Psr7Response(500))));
        $this->assertTrue($method->invoke($service, new Response(new Psr7Response(200))));
    }

    /**
     * End-to-end confidence: with one Packagist request failing at the socket
     * level, composerOutdated() still resolves the healthy package instead of
     * aborting the whole audit.
     */
    public function test_composer_outdated_survives_a_failed_pool_slot(): void
    {
        $service = Mockery::mock(AuditService::class)->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $service->shouldReceive('composerInstalledDirect')->andReturn([
            'foo/bar' => '1.0.0',
            'baz/qux' => '1.0.0',
        ]);

        Http::fake([
            'repo.packagist.org/p2/foo/bar.json' => Http::response([
                'packages' => ['foo/bar' => [['version' => '2.0.0']]],
            ]),
            'repo.packagist.org/p2/baz/qux.json' => fn () => throw new ConnectionException('boom'),
        ]);

        $method = new ReflectionMethod($service, 'composerOutdated');
        $method->setAccessible(true);

        $result = $method->invoke($service);

        $this->assertSame(1, $result['total']);
        $this->assertSame([
            ['name' => 'foo/bar', 'current' => '1.0.0', 'latest' => '2.0.0'],
        ], $result['packages']);
    }

    /**
     * min-release-age guard: a latest release published inside the window is
     * flagged blocked, with the date it unblocks and a whole-day countdown.
     * Time is frozen so both assertions are exact.
     *
     * @see AuditService::annotateReleaseAge()
     */
    public function test_outdated_npm_package_is_flagged_when_inside_the_release_age_window(): void
    {
        CarbonImmutable::setTestNow('2026-07-01 12:00:00');

        $service = Mockery::mock(AuditService::class)->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $service->shouldReceive('npmInstalledDirect')->andReturn([
            'tailwindcss' => '4.3.2',
        ]);
        $service->shouldReceive('npmMinReleaseAgeDays')->andReturn(7);

        Http::fake([
            'registry.npmjs.org/tailwindcss/latest' => Http::response(['version' => '4.3.3']),
            'registry.npmjs.org/tailwindcss' => Http::response([
                'time' => [
                    '4.3.2' => '2026-06-01T14:30:01.000Z',
                    // Published 1 day ago relative to the frozen "now".
                    '4.3.3' => now()->subDays(1)->toIso8601String(),
                ],
            ]),
        ]);

        $method = new ReflectionMethod($service, 'npmOutdated');
        $method->setAccessible(true);

        $result = $method->invoke($service);

        $this->assertSame(1, $result['total']);
        $this->assertTrue($result['packages'][0]['blocked']);
        $this->assertSame(6, $result['packages'][0]['available_in_days']);
        $this->assertSame('2026-07-07', $result['packages'][0]['blocked_until']);
    }

    /**
     * Once the latest release ages past the window, npm will install it, so it
     * must no longer be flagged blocked.
     */
    public function test_outdated_npm_package_is_not_flagged_once_it_ages_out(): void
    {
        CarbonImmutable::setTestNow('2026-07-01 12:00:00');

        $service = Mockery::mock(AuditService::class)->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $service->shouldReceive('npmInstalledDirect')->andReturn([
            'tailwindcss' => '4.3.2',
        ]);
        $service->shouldReceive('npmMinReleaseAgeDays')->andReturn(7);

        Http::fake([
            'registry.npmjs.org/tailwindcss/latest' => Http::response(['version' => '4.3.3']),
            'registry.npmjs.org/tailwindcss' => Http::response([
                'time' => ['4.3.3' => now()->subDays(30)->toIso8601String()],
            ]),
        ]);

        $method = new ReflectionMethod($service, 'npmOutdated');
        $method->setAccessible(true);

        $result = $method->invoke($service);

        $this->assertFalse($result['packages'][0]['blocked']);
        $this->assertNull($result['packages'][0]['available_in_days']);
    }

    /**
     * Fail-open guard: with the guard disabled (0 days), the annotate step
     * short-circuits and never marks anything blocked - even a same-day release.
     */
    public function test_release_age_guard_disabled_never_blocks(): void
    {
        $service = Mockery::mock(AuditService::class)->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $service->shouldReceive('npmInstalledDirect')->andReturn([
            'tailwindcss' => '4.3.2',
        ]);
        $service->shouldReceive('npmMinReleaseAgeDays')->andReturn(0);

        Http::fake([
            'registry.npmjs.org/tailwindcss/latest' => Http::response(['version' => '4.3.3']),
        ]);

        $method = new ReflectionMethod($service, 'npmOutdated');
        $method->setAccessible(true);

        $result = $method->invoke($service);

        $this->assertSame(1, $result['total']);
        $this->assertFalse($result['packages'][0]['blocked']);
    }
}
