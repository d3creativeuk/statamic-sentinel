<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Services\MarketplaceService;
use D3Creative\Sentinel\Tests\TestCase;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\RejectedPromise;
use Illuminate\Support\Facades\Http;

/**
 * Marketplace lookups used to run one blocking request per outdated addon,
 * each waiting its full timeout when statamic.com hung.
 */
class MarketplacePrefetchTest extends TestCase
{
    public function test_prefetched_answers_are_reused_package_by_package(): void
    {
        Http::fake([
            'statamic.com/api/v1/marketplace/packages/statamic/cms/*' => Http::response(['data' => [['version' => '6.2.0', 'security' => true]]]),
            'statamic.com/api/v1/marketplace/packages/acme/seo/*'     => Http::response(['data' => [['version' => '1.1.0', 'security' => false]]]),
            'statamic.com/api/v1/marketplace/packages/acme/private/*' => Http::response([], 404),
        ]);

        $marketplace = new MarketplaceService;
        $marketplace->prefetch(['statamic/cms', 'acme/seo', 'acme/private']);

        Http::assertSentCount(3);

        $this->assertTrue($marketplace->hasSecurityReleaseAfter('statamic/cms', '6.1.0'));
        $this->assertFalse($marketplace->hasSecurityReleaseAfter('acme/seo', '1.0.0'));
        $this->assertSame([], $marketplace->releases('acme/private'));

        // Served from the prefetch, not fetched again.
        Http::assertSentCount(3);
    }

    public function test_a_refused_connection_stops_further_lookups_this_scan(): void
    {
        $attempts = 0;
        Http::fake(['statamic.com/*' => function ($request) use (&$attempts) {
            $attempts++;

            return new RejectedPromise(new ConnectException('refused', $request->toPsrRequest()));
        }]);

        $marketplace = new MarketplaceService;
        $marketplace->prefetch(['statamic/cms', 'acme/seo']);

        $this->assertSame([], $marketplace->releases('acme/other'));
        $this->assertFalse($marketplace->hasSecurityReleaseAfter('acme/third', '1.0.0'));

        $this->assertSame(2, $attempts);
    }

    public function test_a_single_package_is_left_to_the_normal_lookup(): void
    {
        Http::fake(['statamic.com/*' => Http::response(['data' => []])]);

        (new MarketplaceService)->prefetch(['statamic/cms']);

        Http::assertNothingSent();
    }
}
