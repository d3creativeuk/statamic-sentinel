<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Services\MaintenanceReportBuilder;
use D3Creative\Sentinel\Tests\TestCase;

/**
 * The maintenance report tallies update activity by walking consecutive
 * history snapshots within the plan window. These cover the counting rules,
 * the security-related detection, the forward-only severity breakdown, and
 * the window boundary.
 *
 * @see MaintenanceReportBuilder::build()
 */
class MaintenanceReportBuilderTest extends TestCase
{
    /**
     * A fixed history: three snapshots (newest-first, as HistoryService::all()
     * returns) with known versions, package maps, and vuln maps. S0 pre-dates
     * the plan start and is the baseline; S1 and S2 fall inside the window.
     */
    protected function history(): array
    {
        $s0 = [
            'recorded_at'              => '2026-04-01T09:00:00+00:00',
            'statamic'                 => '6.10.0',
            'laravel'                  => '11.0.0',
            'php'                      => '8.3.0',
            'composer_packages'        => ['a/a' => '1.0.0', 'b/b' => '1.0.0', 'c/c' => '1.0.0'],
            'npm_packages'             => ['x' => '1.0.0'],
            'composer_vuln_packages'   => ['a/a' => 2],            // a/a is vulnerable before its bump
            'composer_vuln_severities' => ['a/a' => 'HIGH'],       // severity known -> counts toward breakdown
            'npm_vuln_packages'        => ['x' => 1],              // x is vulnerable before its bump
            // npm_vuln_severities intentionally absent -> simulates a pre-severity snapshot
        ];

        $s1 = [
            'recorded_at'              => '2026-04-20T09:00:00+00:00',
            'statamic'                 => '6.11.0',                // bump
            'laravel'                  => '11.0.0',
            'php'                      => '8.3.0',
            'composer_packages'        => ['a/a' => '1.1.0', 'b/b' => '1.0.0', 'c/c' => '1.1.0'], // a/a + c/c bump
            'npm_packages'             => ['x' => '1.1.0'],        // x bump
            'composer_vuln_packages'   => [],
            'composer_vuln_severities' => [],
            'npm_vuln_packages'        => [],
            'npm_vuln_severities'      => [],
        ];

        $s2 = [
            'recorded_at'              => '2026-05-10T09:00:00+00:00',
            'statamic'                 => '6.12.0',                // bump
            'laravel'                  => '12.0.0',                // bump
            'php'                      => '8.3.0',
            'composer_packages'        => ['a/a' => '1.1.0', 'b/b' => '2.0.0', 'c/c' => '1.1.0'], // b/b bump
            'npm_packages'             => ['x' => '1.1.0'],
            'composer_vuln_packages'   => [],
            'composer_vuln_severities' => [],
            'npm_vuln_packages'        => [],
            'npm_vuln_severities'      => [],
        ];

        return [$s2, $s1, $s0]; // newest-first
    }

    public function test_counts_updates_since_the_plan_start(): void
    {
        $report = MaintenanceReportBuilder::build($this->history(), [
            'plan_name'  => 'Gold Care Plan',
            'start_date' => '2026-04-14',
        ]);

        $this->assertTrue($report['has_data']);

        // Platform version bumps across the two in-window intervals.
        $this->assertSame(2, $report['platform']['statamic']['count']);
        $this->assertSame('6.10.0', $report['platform']['statamic']['from']);
        $this->assertSame('6.12.0', $report['platform']['statamic']['to']);
        $this->assertSame(1, $report['platform']['laravel']['count']);
        $this->assertSame(0, $report['platform']['php']['count']);

        // Composer: a/a + c/c (S0->S1) and b/b (S1->S2) = 3 total bumps.
        $this->assertSame(3, $report['composer']['updates']);

        // npm: x (S0->S1) = 1 bump.
        $this->assertSame(1, $report['npm']['updates']);

        $this->assertSame(7, $report['total_updates']);

        // Plan context is echoed back for the email.
        $this->assertSame('Gold Care Plan', $report['plan']['name']);
        $this->assertSame('14 Apr 2026', $report['plan']['start']);
    }

    public function test_flags_security_related_updates_and_severity(): void
    {
        $report = MaintenanceReportBuilder::build($this->history(), ['start_date' => '2026-04-14']);

        // Composer: only a/a was vulnerable before its bump (c/c and b/b were not).
        $this->assertSame(1, $report['composer']['security_updates']);
        $this->assertSame(1, $report['composer']['security_by_severity']['HIGH']);
        $this->assertSame(1, $report['composer']['security_updates_with_severity']);

        // npm: x was vulnerable before its bump -> security-related, but S0 had
        // no severity map, so it counts toward the total without a severity split.
        $this->assertSame(1, $report['npm']['security_updates']);
        $this->assertSame(0, $report['npm']['security_updates_with_severity']);

        $this->assertSame(2, $report['total_security_updates']);
    }

    public function test_snapshots_before_the_plan_start_are_excluded(): void
    {
        // Start after S1, so only the S1->S2 interval is in-window.
        $report = MaintenanceReportBuilder::build($this->history(), ['start_date' => '2026-05-01']);

        // Only S1->S2 counts: statamic 6.11->6.12 = 1 (the 6.10->6.11 bump is excluded).
        $this->assertSame(1, $report['platform']['statamic']['count']);
        $this->assertSame(1, $report['platform']['laravel']['count']);

        // Composer: only b/b bumped in S1->S2.
        $this->assertSame(1, $report['composer']['updates']);
        // npm x bumped in S0->S1, which is now out of window.
        $this->assertSame(0, $report['npm']['updates']);
    }

    public function test_header_range_starts_at_the_plan_start_date(): void
    {
        // Plan starts well before the earliest snapshot (2026-04-01); the header
        // "since" must still show the plan start so it matches the intro line,
        // rather than being clamped to the first recorded scan.
        $report = MaintenanceReportBuilder::build($this->history(), ['start_date' => '2026-01-14']);

        $this->assertSame('14 Jan 2026', $report['since']);
        $this->assertSame('10 May 2026', $report['to']); // newest snapshot
    }

    public function test_empty_history_has_no_data(): void
    {
        $report = MaintenanceReportBuilder::build([], ['start_date' => '2026-04-14']);

        $this->assertFalse($report['has_data']);
        $this->assertSame(0, $report['total_updates']);
        $this->assertSame(0, $report['total_security_updates']);
        // Plan context still echoes through.
        $this->assertSame('14 Apr 2026', $report['plan']['start']);
    }
}
