<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Services\AuditService;
use D3Creative\Sentinel\Services\HistoryService;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;

/**
 * A package-lock.json that exists but PHP can't decode used to read as "not
 * found", the state a site with no npm gets. npm checks switched off
 * silently, and history recorded zero vulnerabilities and no packages, so
 * the next update report said every open npm issue was resolved. Any
 * installed package can cause it: npm copies manifest fields such as
 * `license` verbatim into the lock, and PHP rejects a lone UTF-16 surrogate.
 */
class UnreadableLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Storage::fake('local');
        Http::fake(['api.osv.dev/*' => Http::response(['results' => [['vulns' => []]]])]);
    }

    protected function tearDown(): void
    {
        @unlink(base_path('package-lock.json'));
        @unlink(base_path('package.json'));

        parent::tearDown();
    }

    public function test_a_missing_lock_is_still_unavailable(): void
    {
        $this->assertSame('unavailable', $this->invokeAudit('npmAudit')['status']);
    }

    public function test_a_lone_surrogate_from_a_package_manifest_fails_the_check(): void
    {
        $this->writeLock('{"lockfileVersion":3,"packages":{"node_modules/evil":{"version":"1.0.0","license":"MIT\ud800"}}}');

        $result = $this->invokeAudit('npmAudit');

        $this->assertSame('error', $result['status']);
        $this->assertTrue($result['lock_unreadable']);
    }

    public function test_merge_conflict_markers_fail_the_check(): void
    {
        $this->writeLock("{\n<<<<<<< HEAD\n\"lockfileVersion\": 3\n=======\n\"lockfileVersion\": 2\n>>>>>>> main\n}");

        $this->assertSame('error', $this->invokeAudit('npmAudit')['status']);
    }

    public function test_a_byte_order_mark_is_tolerated(): void
    {
        $this->writeLock("\xEF\xBB\xBF" . '{"lockfileVersion":3,"packages":{"node_modules/lodash":{"version":"4.17.21"}}}');

        $this->assertSame('ok', $this->invokeAudit('npmAudit')['status']);
    }

    public function test_deep_nesting_is_tolerated(): void
    {
        $deep = str_repeat('{"a":', 600) . '1' . str_repeat('}', 600);
        $this->writeLock('{"lockfileVersion":3,"packages":{"node_modules/x":{"version":"1.0.0","engines":' . $deep . '}}}');

        $this->assertSame('ok', $this->invokeAudit('npmAudit')['status']);
    }

    public function test_the_update_check_also_reports_a_failure(): void
    {
        file_put_contents(base_path('package.json'), '{"dependencies":{"lodash":"^4.17.0"}}');
        $this->writeLock('{"lockfileVersion":3,"packages":{"node_modules/evil":{"version":"1.0.0","license":"MIT\ud800"}}}');

        $this->assertTrue($this->invokeAudit('npmOutdated')['error']);
    }

    public function test_history_keeps_the_previous_packages_and_vulnerabilities(): void
    {
        $history = new HistoryService;

        $history->recordIfChanged($this->audit(['status' => 'vulnerable', 'total_vulns' => 1, 'installed' => ['lodash' => '4.17.20']]));
        $history->recordIfChanged($this->audit(['status' => 'error', 'lock_unreadable' => true, 'total_vulns' => 0, 'installed' => []], '6.1.0'));

        $latest = $history->all()[0];
        $this->assertSame('6.1.0', $latest['statamic']);
        $this->assertSame(1, $latest['npm_vulns']);
        $this->assertSame(['lodash' => '4.17.20'], $latest['npm_packages']);
    }

    protected function writeLock(string $json): void
    {
        file_put_contents(base_path('package-lock.json'), $json);
    }

    protected function invokeAudit(string $method): array
    {
        $service = new AuditService;
        $m       = new ReflectionMethod($service, $method);
        $m->setAccessible(true);

        return $m->invoke($service);
    }

    protected function audit(array $npm, string $statamic = '6.0.0'): array
    {
        $vulns = $npm['total_vulns'];

        return [
            'statamic' => ['current' => $statamic],
            'laravel'  => ['version' => '12.0.0'],
            'php'      => ['version' => '8.4.0'],
            'composer' => ['status' => 'ok', 'total_vulns' => 0, 'outdated' => ['total' => 0]],
            'npm'      => $npm + [
                'by_package' => $vulns ? [['name' => 'lodash', 'count' => $vulns, 'highest' => 'HIGH']] : [],
                'outdated'   => ['total' => 0],
            ],
        ];
    }
}
