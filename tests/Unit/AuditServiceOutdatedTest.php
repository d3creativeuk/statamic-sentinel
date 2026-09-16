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
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Fast path: the publish time comes from the `/latest` manifest's
     * `_npmOperationalInternal.tmp`, so the full registry document (39 MB for
     * vite, which timed out in production) is never requested.
     *
     * @see AuditService::npmPublishedAtFromManifest()
     */
    public function test_release_age_uses_the_latest_manifest_publish_time(): void
    {
        CarbonImmutable::setTestNow('2026-09-15 12:00:00');

        $service = $this->npmService(['vite' => '8.2.2'], 7);

        Http::fake([
            'registry.npmjs.org/vite/latest' => Http::response([
                'version' => '8.3.0',
                // 1789039826195 ms = 2026-09-10T11:30:26Z
                '_npmOperationalInternal' => ['tmp' => 'tmp/vite_8.3.0_1789039826195_0.15890598475191897'],
            ]),
        ]);

        $pkg = $this->invokeNpmOutdated($service)['packages'][0];

        $this->assertTrue($pkg['blocked']);
        $this->assertSame('2026-09-17', $pkg['blocked_until']);
        $this->assertSame(2, $pkg['available_in_days']);
        $this->assertFalse($pkg['release_age_unknown']);
        $this->assertFullDocumentNotRequested('vite');
    }

    /**
     * Scoped packages carry only the unscoped basename in `tmp`, so the parse
     * must anchor on the timestamp tail, not the package name.
     */
    public function test_release_age_fast_path_handles_scoped_packages(): void
    {
        CarbonImmutable::setTestNow('2026-09-15 12:00:00');

        $service = $this->npmService(['@alpinejs/collapse' => '3.17.2'], 7);

        Http::fake([
            'registry.npmjs.org/@alpinejs/collapse/latest' => Http::response([
                'version' => '3.17.3',
                // 1789410036019 ms = 2026-09-14T18:20:36Z
                '_npmOperationalInternal' => ['tmp' => 'tmp/collapse_3.17.3_1789410036019_0.655826726596169'],
            ]),
        ]);

        $pkg = $this->invokeNpmOutdated($service)['packages'][0];

        $this->assertTrue($pkg['blocked']);
        $this->assertSame('2026-09-21', $pkg['blocked_until']);
        $this->assertSame(7, $pkg['available_in_days']);
        $this->assertFullDocumentNotRequested('@alpinejs/collapse');
    }

    /**
     * Fast path outside the window: installable, and still no full-document
     * request.
     */
    public function test_release_age_fast_path_outside_the_window_is_not_blocked(): void
    {
        CarbonImmutable::setTestNow('2026-09-15 12:00:00');

        $service = $this->npmService(['@tailwindcss/forms' => '0.5.10'], 7);

        Http::fake([
            'registry.npmjs.org/@tailwindcss/forms/latest' => Http::response([
                'version' => '0.5.11',
                // 1765999359707 ms = 2025-12-17T19:22:39Z
                '_npmOperationalInternal' => ['tmp' => 'tmp/forms_0.5.11_1765999359707_0.946032610272489'],
            ]),
        ]);

        $pkg = $this->invokeNpmOutdated($service)['packages'][0];

        $this->assertFalse($pkg['blocked']);
        $this->assertNull($pkg['available_in_days']);
        $this->assertFalse($pkg['release_age_unknown']);
        $this->assertFullDocumentNotRequested('@tailwindcss/forms');
    }

    /**
     * The `tmp` field is undocumented, so anything that doesn't parse to a
     * plausible timestamp must fall back to the full document's `time` map.
     */
    #[DataProvider('malformedTmpProvider')]
    public function test_release_age_falls_back_to_the_full_document_when_tmp_is_unusable($tmp): void
    {
        CarbonImmutable::setTestNow('2026-09-15 12:00:00');

        $service = $this->npmService(['vite' => '8.2.2'], 7);

        $latest = ['version' => '8.3.0'];
        if ($tmp !== null) {
            $latest['_npmOperationalInternal'] = ['tmp' => $tmp];
        }

        Http::fake([
            'registry.npmjs.org/vite/latest' => Http::response($latest),
            'registry.npmjs.org/vite' => Http::response([
                'time' => ['8.3.0' => '2026-09-14T12:00:00.000Z'],
            ]),
        ]);

        $pkg = $this->invokeNpmOutdated($service)['packages'][0];

        $this->assertTrue($pkg['blocked']);
        $this->assertSame('2026-09-21', $pkg['blocked_until']);
        $this->assertFalse($pkg['release_age_unknown']);
        Http::assertSent(fn ($request) => $request->url() === 'https://registry.npmjs.org/vite');
    }

    public static function malformedTmpProvider(): array
    {
        return [
            'missing'         => [null],
            'no timestamp'    => ['tmp/vite_8.3.0_notatimestamp'],
            'not a string'    => [['tmp/vite_8.3.0_1789039826195_0.15']],
            'before 2010'     => ['tmp/vite_8.3.0_1199145600000_0.15'],
            'in the future'   => ['tmp/vite_8.3.0_4102444800000_0.15'],
        ];
    }

    /**
     * No fast path and the fallback fails (the production vite timeout): fail
     * open, but mark the row so the view can say the check didn't run.
     */
    #[DataProvider('failedDocumentProvider')]
    public function test_release_age_is_marked_unknown_when_the_fallback_fails(string $failure): void
    {
        CarbonImmutable::setTestNow('2026-09-15 12:00:00');

        $service = $this->npmService(['vite' => '8.2.2'], 7);

        Http::fake([
            'registry.npmjs.org/vite/latest' => Http::response(['version' => '8.3.0']),
            'registry.npmjs.org/vite' => $failure === 'timeout'
                ? fn () => throw new ConnectionException('cURL error 28: Operation timed out')
                : Http::response('', 500),
        ]);

        $result = $this->invokeNpmOutdated($service);
        $pkg    = $result['packages'][0];

        $this->assertSame(1, $result['total']);
        $this->assertFalse($pkg['blocked']);
        $this->assertNull($pkg['blocked_until']);
        $this->assertTrue($pkg['release_age_unknown']);
    }

    public static function failedDocumentProvider(): array
    {
        return [
            'connection timeout' => ['timeout'],
            'server error'       => ['500'],
        ];
    }

    /**
     * With the guard disabled there is nothing to check, so no row may claim
     * the check failed - even one with no publish time at all.
     */
    public function test_release_age_guard_disabled_never_marks_unknown(): void
    {
        $service = $this->npmService(['vite' => '8.2.2', 'tailwindcss' => '4.3.2'], 0);

        Http::fake([
            'registry.npmjs.org/vite/latest' => Http::response(['version' => '8.3.0']),
            'registry.npmjs.org/tailwindcss/latest' => Http::response(['version' => '4.3.3']),
        ]);

        $result = $this->invokeNpmOutdated($service);

        $this->assertSame(2, $result['total']);
        foreach ($result['packages'] as $pkg) {
            $this->assertFalse($pkg['release_age_unknown']);
            $this->assertFalse($pkg['blocked']);
        }
        Http::assertSentCount(2);
    }

    /**
     * The platform check and composerOutdated() both need Packagist's
     * statamic/cms and laravel/framework feeds; the second must reuse the
     * first rather than download ~1.4 MB again.
     */
    public function test_composer_outdated_reuses_platform_packagist_responses(): void
    {
        $service = Mockery::mock(AuditService::class)->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $service->shouldReceive('composerInstalledDirect')->andReturn([
            'laravel/framework' => '13.0.0',
            'foo/bar'           => '1.0.0',
        ]);

        Http::fake([
            'repo.packagist.org/p2/statamic/cms.json' => Http::response([
                'packages' => ['statamic/cms' => [['version' => 'v6.0.0']]],
            ]),
            'repo.packagist.org/p2/laravel/framework.json' => Http::response([
                'packages' => ['laravel/framework' => [['version' => 'v13.32.0']]],
            ]),
            'repo.packagist.org/p2/foo/bar.json' => Http::response([
                'packages' => ['foo/bar' => [['version' => '2.0.0']]],
            ]),
            'endoflife.date/*' => Http::response([]),
        ]);

        // Statamic reads its own version from the host's composer.lock, which
        // the test app doesn't have.
        \Facades\Statamic\Version::shouldReceive('get')->andReturn('6.0.0');

        $platform = new ReflectionMethod($service, 'fetchPlatformLatestVersions');
        $platform->setAccessible(true);
        $platform->invoke($service);

        $outdated = new ReflectionMethod($service, 'composerOutdated');
        $outdated->setAccessible(true);
        $result = $outdated->invoke($service);

        $this->assertSame(['laravel/framework', 'foo/bar'], array_column($result['packages'], 'name'));
        $this->assertSame('13.32.0', $result['packages'][0]['latest']);
        $this->assertCount(1, Http::recorded(
            fn ($request) => $request->url() === 'https://repo.packagist.org/p2/laravel/framework.json'
        ));
    }

    /**
     * Registries answer uncompressed unless asked; every request must ask.
     */
    public function test_registry_requests_ask_for_gzip(): void
    {
        $service = $this->npmService(['vite' => '8.2.2'], 7);

        Http::fake([
            'registry.npmjs.org/vite/latest' => Http::response(['version' => '8.3.0']),
            'registry.npmjs.org/vite' => Http::response(['time' => []]),
        ]);

        $this->invokeNpmOutdated($service);

        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => ! $request->hasHeader('Accept-Encoding', 'gzip'));
    }

    /**
     * Only statamic/cms and Statamic addons (extra.statamic in composer.lock)
     * can be on the marketplace, so nothing else gets a lookup.
     */
    public function test_marketplace_is_only_queried_for_statamic_and_addons(): void
    {
        $service = new AuditService;

        $cache = new \ReflectionProperty($service, 'lockfileCache');
        $cache->setAccessible(true);
        $cache->setValue($service, [
            base_path('composer.lock') => [
                'packages' => [
                    ['name' => 'statamic/cms', 'version' => 'v6.0.0'],
                    ['name' => 'laravel/framework', 'version' => 'v13.0.0'],
                    ['name' => 'acme/seo', 'version' => '1.0.0', 'extra' => ['statamic' => ['name' => 'SEO']]],
                ],
            ],
        ]);

        $marketplace = Mockery::mock(\D3Creative\Sentinel\Services\MarketplaceService::class);
        $marketplace->shouldReceive('hasSecurityReleaseAfter')->once()->with('statamic/cms', '6.0.0')->andReturn(false);
        $marketplace->shouldReceive('hasSecurityReleaseAfter')->once()->with('acme/seo', '1.0.0')->andReturn(true);
        $marketplace->shouldNotReceive('hasSecurityReleaseAfter')->with('laravel/framework', Mockery::any());
        $this->app->instance(\D3Creative\Sentinel\Services\MarketplaceService::class, $marketplace);

        $method = new ReflectionMethod($service, 'annotateOutdatedSecurity');
        $method->setAccessible(true);

        $result = $method->invoke($service, ['outdated' => ['packages' => [
            ['name' => 'statamic/cms', 'current' => '6.0.0', 'latest' => '6.1.0'],
            ['name' => 'laravel/framework', 'current' => '13.0.0', 'latest' => '13.32.0'],
            ['name' => 'acme/seo', 'current' => '1.0.0', 'latest' => '1.1.0'],
        ]]], 'composer');

        $this->assertSame([false, false, true], array_column($result['outdated']['packages'], 'security_update'));
        $this->assertSame(1, $result['outdated']['vendor_security_updates_total']);
    }

    protected function npmService(array $installed, int $minReleaseAgeDays)
    {
        $service = Mockery::mock(AuditService::class)->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $service->shouldReceive('npmInstalledDirect')->andReturn($installed);
        $service->shouldReceive('npmMinReleaseAgeDays')->andReturn($minReleaseAgeDays);

        return $service;
    }

    protected function invokeNpmOutdated($service): array
    {
        $method = new ReflectionMethod($service, 'npmOutdated');
        $method->setAccessible(true);

        return $method->invoke($service);
    }

    protected function assertFullDocumentNotRequested(string $name): void
    {
        Http::assertNotSent(fn ($request) => $request->url() === "https://registry.npmjs.org/{$name}");
    }
}
