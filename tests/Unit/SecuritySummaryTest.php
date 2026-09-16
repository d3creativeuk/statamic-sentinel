<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Support\SecuritySummary;
use D3Creative\Sentinel\Tests\TestCase;

/**
 * The widget added vendor-flagged security releases to its count, but the
 * utility only listed them when OSV found nothing. With 2 advisories and 1
 * vendor release the widget said 3, the report said 2, and the vendor
 * release wasn't listed anywhere.
 */
class SecuritySummaryTest extends TestCase
{
    public function test_total_counts_advisories_and_vendor_only_releases(): void
    {
        $ecosystem = [
            'status'   => 'vulnerable',
            'counts'   => ['CRITICAL' => 0, 'HIGH' => 1, 'MEDIUM' => 1, 'LOW' => 0, 'UNKNOWN' => 0],
            'outdated' => [
                'vendor_security_updates_total' => 1,
                'packages' => [
                    ['name' => 'statamic/cms', 'security_source' => 'both'],
                    ['name' => 'acme/seo', 'security_source' => 'vendor', 'current' => '1.0.0', 'latest' => '1.1.0'],
                    ['name' => 'acme/plain', 'security_source' => null],
                ],
            ],
        ];

        $this->assertSame(3, SecuritySummary::total($ecosystem));
        $this->assertSame(1, SecuritySummary::vendorOnlyCount($ecosystem));
        $this->assertSame(['acme/seo'], array_column(SecuritySummary::vendorOnlyPackages($ecosystem), 'name'));
    }

    public function test_old_payloads_without_the_keys_count_zero(): void
    {
        $this->assertSame(0, SecuritySummary::total(['status' => 'ok']));
        $this->assertSame([], SecuritySummary::vendorOnlyPackages(['status' => 'ok']));
    }
}
