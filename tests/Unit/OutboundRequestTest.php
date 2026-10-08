<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Services\AuditService;
use D3Creative\Sentinel\Tests\TestCase;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\RejectedPromise;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use ReflectionMethod;

/**
 * How Sentinel talks to the outside world: a short connect timeout, no
 * redirects, and no second wait on a host that already failed this scan.
 */
class OutboundRequestTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_requests_get_a_connect_timeout_and_do_not_follow_redirects(): void
    {
        $seen = null;
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response(['ok' => true]);
        });

        AuditService::prepare(Http::withOptions([]), 5)->get('https://repo.packagist.org/p2/x/y.json');

        $this->assertSame(AuditService::CONNECT_TIMEOUT, $seen['connect_timeout']);
        $this->assertSame(5, $seen['timeout']);
        $this->assertFalse($seen['allow_redirects']);
    }

    /**
     * Every outbound call goes through prepare(), so none follows redirects
     * or waits the full timeout to connect.
     */
    public function test_no_request_bypasses_the_helper(): void
    {
        foreach (['AuditService', 'MarketplaceService'] as $class) {
            $source = file_get_contents(__DIR__ . "/../../src/Services/{$class}.php");

            $this->assertSame($class === 'AuditService' ? 1 : 0, substr_count($source, '->timeout('), $class);
            $this->assertDoesNotMatchRegularExpression('/Http::(get|post|timeout|withHeaders)\(/', $source, $class);
        }
    }

    public function test_osv_is_not_asked_again_after_it_refused_a_connection(): void
    {
        Http::fake(['api.osv.dev/*' => fn ($request) => new RejectedPromise(new ConnectException('refused', $request->toPsrRequest()))]);

        $service = new AuditService;
        $query   = new ReflectionMethod($service, 'queryOsv');
        $query->setAccessible(true);
        $one     = [['package' => ['name' => 'a/b', 'ecosystem' => 'Packagist'], 'version' => '1.0.0']];

        $this->assertSame('error', $query->invoke($service, $one, 1)['status']);
        $this->assertSame('error', $query->invoke($service, $one, 1)['status']);

        Http::assertSentCount(1);
    }

    public function test_the_update_check_fails_fast_when_packagist_is_down(): void
    {
        Http::fake([
            'repo.packagist.org/*' => fn ($request) => new RejectedPromise(new ConnectException('refused', $request->toPsrRequest())),
            'endoflife.date/*'     => Http::response([]),
        ]);

        $service = Mockery::mock(AuditService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('composerInstalledDirect')->andReturn(['acme/pkg' => '1.0.0', 'acme/other' => '1.0.0']);

        $platform = new ReflectionMethod($service, 'fetchPlatformLatestVersions');
        $platform->setAccessible(true);
        try {
            $platform->invoke($service);
        } catch (\Throwable $e) {
            // Statamic::version() needs a composer.lock; the pool has run by then.
        }

        $outdated = new ReflectionMethod($service, 'composerOutdated');
        $outdated->setAccessible(true);
        $result = $outdated->invoke($service);

        $this->assertTrue($result['error']);
        $this->assertSame(['acme/pkg', 'acme/other'], $result['unchecked']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'p2/acme/'));
    }
}
