<?php

namespace D3Creative\Sentinel\Tests\Unit;

use Carbon\Carbon;
use D3Creative\Sentinel\Services\AuditService;
use D3Creative\Sentinel\Tests\TestCase;
use ReflectionMethod;

/**
 * Between scans the cached audit is reconciled against the live install. A
 * partial update (past the security fix, short of the newest release) used
 * to keep the security pill, "N behind" and old support status until the
 * next scan, because only a jump to `latest` cleared them.
 */
class ReconcileLiveTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_statamic_flags_clear_per_source_as_the_live_version_passes_each_fix(): void
    {
        $cached = [
            'current'                   => '6.0.0',
            'latest'                    => '6.5.0',
            'is_latest'                 => false,
            'releases_behind'           => 5,
            'status'                    => 'outdated',
            'security_update_available' => true,
            'security_source'           => 'both',
            'security_fixed_in'         => ['osv' => '6.2.0', 'vendor' => '6.4.0'],
            'newer_versions'            => ['6.5.0', '6.4.0', '6.3.0', '6.2.0', '6.1.0'],
        ];

        $midway = $this->invokeProtected('reconcileStatamicAgainstLive', $cached, '6.3.0');
        $this->assertTrue($midway['security_update_available']);
        $this->assertSame('vendor', $midway['security_source']);
        $this->assertSame(2, $midway['releases_behind']);

        $pastFixes = $this->invokeProtected('reconcileStatamicAgainstLive', $cached, '6.4.1');
        $this->assertFalse($pastFixes['security_update_available']);
        $this->assertNull($pastFixes['security_source']);
        $this->assertSame(1, $pastFixes['releases_behind']);
        $this->assertSame('outdated', $pastFixes['status']);
    }

    public function test_a_source_with_no_stored_fix_stays_flagged(): void
    {
        $cached = [
            'current'                   => '6.0.0',
            'latest'                    => '6.5.0',
            'security_update_available' => true,
            'security_source'           => 'osv',
            'security_fixed_in'         => ['osv' => null, 'vendor' => null],
        ];

        $this->assertTrue($this->invokeProtected('reconcileStatamicAgainstLive', $cached, '6.4.9')['security_update_available']);
    }

    public function test_audits_cached_before_fixed_in_existed_keep_their_flags(): void
    {
        $cached = [
            'current'                   => '6.0.0',
            'latest'                    => '6.5.0',
            'releases_behind'           => 5,
            'security_update_available' => true,
            'security_source'           => 'osv',
        ];

        $result = $this->invokeProtected('reconcileStatamicAgainstLive', $cached, '6.4.9');

        $this->assertTrue($result['security_update_available']);
        $this->assertSame(5, $result['releases_behind']);
    }

    public function test_laravel_upgrade_recomputes_support_status_and_security(): void
    {
        Carbon::setTestNow('2026-09-16');

        $cached = [
            'version'                   => '11.0.0',
            'latest'                    => '13.5.0',
            'status'                    => 'eol',
            'label'                     => 'End of Life',
            'security_update_available' => true,
            'security_fixed_in'         => ['osv' => '12.1.0', 'vendor' => null],
            'newer_versions'            => ['13.5.0', '13.0.0', '12.1.0'],
        ];

        $result = $this->invokeProtected('reconcileLaravelAgainstLive', $cached, '13.0.0');

        $this->assertSame('active', $result['status']);
        $this->assertSame('Active Support', $result['label']);
        $this->assertFalse($result['security_update_available']);
        $this->assertSame(1, $result['releases_behind']);
    }

    public function test_php_status_is_recomputed_from_stored_branches(): void
    {
        Carbon::setTestNow('2026-09-16');

        $branches = [
            ['cycle' => '8.5', 'latest' => '8.5.7', 'support' => '2027-12-31', 'eol' => '2029-12-31', 'releaseDate' => '2025-11-20'],
            ['cycle' => '8.1', 'latest' => '8.1.33', 'support' => '2023-11-25', 'eol' => '2025-12-31', 'releaseDate' => '2021-11-25'],
        ];

        $cached = ['version' => '8.1.33', 'latest' => '8.5.7', 'status' => 'eol', 'label' => 'End of Life', 'branches' => $branches];

        $result = $this->invokeProtected('reconcilePhpAgainstLive', $cached, '8.5.5');

        $this->assertSame('active', $result['status']);
        $this->assertSame(2, $result['releases_behind']);
        $this->assertFalse($result['is_latest']);
    }

    public function test_outdated_package_flag_clears_once_live_reaches_the_fix(): void
    {
        $ecosystem = ['outdated' => ['packages' => [
            ['name' => 'acme/a', 'current' => '1.0.0', 'latest' => '2.0.0', 'security_update' => true, 'security_source' => 'osv', 'security_fixed_in' => ['osv' => '1.2.0', 'vendor' => null]],
            ['name' => 'acme/b', 'current' => '1.0.0', 'latest' => '2.0.0', 'security_update' => true, 'security_source' => 'vendor', 'security_fixed_in' => ['osv' => null, 'vendor' => '1.9.0']],
        ]]];

        $result = $this->invokeProtected('pruneOutdatedAgainstLive', $ecosystem, ['acme/a' => '1.2.0', 'acme/b' => '1.5.0']);

        $this->assertSame([false, true], array_column($result['outdated']['packages'], 'security_update'));
        $this->assertSame(1, $result['outdated']['security_updates_total']);
        $this->assertSame(1, $result['outdated']['vendor_security_updates_total']);
    }

    public function test_fixed_in_is_the_highest_of_each_advisorys_lowest_fix(): void
    {
        $audit = ['severities' => [
            'HIGH' => ['vulns' => [
                ['package' => 'acme/a', 'fix_available' => true, 'fixed_versions' => ['0.9.0', '1.2.0']],
                ['package' => 'acme/a', 'fix_available' => true, 'fixed_versions' => ['2.0.1', '1.3.5']],
                ['package' => 'acme/other', 'fix_available' => true, 'fixed_versions' => ['9.0.0']],
            ]],
        ]];

        $this->assertSame('1.3.5', $this->invokeProtected('osvFixedIn', 'acme/a', '1.1.0', $audit));

        $audit['severities']['HIGH']['vulns'][] = ['package' => 'acme/a', 'fix_available' => true, 'fixed_versions' => ['0.5.0']];
        $this->assertNull($this->invokeProtected('osvFixedIn', 'acme/a', '1.1.0', $audit));
    }

    public function test_summaries_keep_fixed_versions_per_package_but_not_git_commits(): void
    {
        $summary = $this->invokeProtected('summariseVuln', ['affected' => [
            ['package' => ['name' => 'acme/a'], 'ranges' => [
                ['type' => 'ECOSYSTEM', 'events' => [['introduced' => '0'], ['fixed' => 'v1.2.0']]],
                ['type' => 'GIT', 'events' => [['introduced' => '0'], ['fixed' => 'a1b2c3d']]],
            ]],
        ]]);

        $this->assertTrue($summary['fix_available']);
        $this->assertSame(['acme/a' => ['1.2.0']], $summary['fixed']);
    }

    protected function invokeProtected(string $method, ...$args)
    {
        $service    = new AuditService;
        $reflection = new ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($service, ...$args);
    }
}
