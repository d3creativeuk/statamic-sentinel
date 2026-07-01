<?php

namespace D3Creative\Sentinel\Tests\Unit;

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
}
