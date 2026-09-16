<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Tests\TestCase;

/**
 * Laravel's throttle middleware keys its counter on the user id plus an
 * optional prefix. Without a prefix every throttled route shared one counter
 * per user, checked against each route's own limit, so a few previews and a
 * save could 429 the next Send. Each Sentinel route now has its own prefix.
 */
class RouteThrottleTest extends TestCase
{
    public function test_every_throttled_route_has_its_own_prefix(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/ServiceProvider.php');

        preg_match_all("/throttle:[^']*/", $source, $all);
        preg_match_all("/throttle:\\d+,\\d+,(sentinel\\.[a-z0-9.\\-]+)/", $source, $prefixed);

        $this->assertNotEmpty($all[0]);
        $this->assertCount(count($all[0]), $prefixed[1], 'A throttle middleware is missing its prefix.');
        $this->assertSame($prefixed[1], array_values(array_unique($prefixed[1])), 'Throttle prefixes must be unique per route.');
    }
}
