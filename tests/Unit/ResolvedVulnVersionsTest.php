<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Services\HistoryService;
use D3Creative\Sentinel\Services\UpdateReportBuilder;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

/**
 * The update report listed resolved vulnerabilities by name only, so a
 * client couldn't see what changed to fix them. Snapshots now keep the
 * installed versions of vulnerable packages, and resolved rows show the
 * move, like the package changes above them.
 */
class ResolvedVulnVersionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array', 'app.url' => 'https://example.test']);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        @unlink(base_path('composer.lock'));
        @unlink(base_path('package-lock.json'));

        parent::tearDown();
    }

    public function test_snapshots_keep_versions_of_packages_vulnerable_now_or_before(): void
    {
        $history = new HistoryService;

        $this->writeLocks(['guzzlehttp/guzzle' => '7.8.0', 'monolog/monolog' => '3.0.0'], ['braces' => ['3.0.2', '2.3.2']]);
        $history->recordIfChanged($this->audit(['guzzlehttp/guzzle' => 9], ['braces' => 1]));

        $first = $history->all()[0];
        $this->assertSame(['guzzlehttp/guzzle' => '7.8.0'], $first['composer_vuln_versions']);
        $this->assertSame(['braces' => '2.3.2, 3.0.2'], $first['npm_vuln_versions']);

        // Guzzle fixed, braces removed: both still get a version entry (or
        // none, for the removed one) from the new lock files.
        $this->writeLocks(['guzzlehttp/guzzle' => '7.10.0', 'monolog/monolog' => '3.0.0'], []);
        $history->recordIfChanged($this->audit([], []));

        $latest = $history->all()[0];
        $this->assertSame(['guzzlehttp/guzzle' => '7.10.0'], $latest['composer_vuln_versions']);
        $this->assertSame(['braces' => null], $latest['npm_vuln_versions']);

        $vulns = UpdateReportBuilder::build($history->all()[0], $history->all()[1])['vulns'];

        $this->assertSame(['from' => '7.8.0', 'to' => '7.10.0', 'removed' => false], array_intersect_key($vulns['composer_resolved_packages'][0], array_flip(['from', 'to', 'removed'])));
        $this->assertSame(['from' => '2.3.2', 'to' => null, 'removed' => true], array_intersect_key($vulns['npm_resolved_packages'][0], array_flip(['from', 'to', 'removed'])));
    }

    public function test_several_installed_versions_read_as_lowest_before_and_highest_after(): void
    {
        $row = UpdateReportBuilder::build(
            ['npm_vulns' => 0, 'npm_vuln_packages' => [], 'npm_vuln_versions' => ['braces' => '3.0.3, 3.0.10']],
            ['npm_vulns' => 1, 'npm_vuln_packages' => ['braces' => 1], 'npm_vuln_versions' => ['braces' => '2.3.2, 3.0.2']]
        )['vulns']['npm_resolved_packages'][0];

        $this->assertSame(['2.3.2', '3.0.10'], [$row['from'], $row['to']]);
    }

    /**
     * Snapshots recorded before versions were kept get the current ones on
     * the next scan, so the report already waiting shows 7.10.0.
     */
    public function test_the_waiting_report_gets_current_versions_on_the_next_scan(): void
    {
        Storage::disk('local')->put(HistoryService::RELATIVE_PATH, json_encode([
            $this->legacy([]),
            $this->legacy(['guzzlehttp/guzzle' => 9]),
        ]));
        $this->writeLocks(['guzzlehttp/guzzle' => '7.10.0'], []);

        (new HistoryService)->recordIfChanged($this->audit([], []));

        $all = (new HistoryService)->all();
        $this->assertCount(2, $all);

        $row = UpdateReportBuilder::build($all[0], $all[1])['vulns']['composer_resolved_packages'][0];
        $this->assertSame([null, '7.10.0', false], [$row['from'], $row['to'], $row['removed']]);
    }

    /**
     * russell-ldp: the snapshot was recorded after one with no
     * vulnerabilities, which was then deleted, so the report paired it with
     * an older one whose packages it never looked up. Those read as removed.
     */
    public function test_a_package_the_latest_snapshot_never_looked_up_is_not_called_removed(): void
    {
        Storage::disk('local')->put(HistoryService::RELATIVE_PATH, json_encode([
            $this->legacy([]),
            $this->legacy([]),
            $this->legacy(['guzzlehttp/guzzle' => 9]),
        ]));
        $this->writeLocks(['guzzlehttp/guzzle' => '7.15.5'], []);
        $history = new HistoryService;

        // Recorded against the empty snapshot, so it looks up nothing.
        $history->recordIfChanged($this->audit([], []));
        $all = $history->all();
        $this->assertSame([], $all[0]['composer_vuln_versions']);

        // The empty snapshot is deleted: the report now pairs with guzzle's.
        $entries = $all;
        array_splice($entries, 1, 1);
        Storage::disk('local')->put(HistoryService::RELATIVE_PATH, json_encode($entries));

        $row = UpdateReportBuilder::build($entries[0], $entries[1])['vulns']['composer_resolved_packages'][0];
        $this->assertSame([null, null, false], [$row['from'], $row['to'], $row['removed']]);

        // The next unchanged scan fills it in.
        $history->recordIfChanged($this->audit([], []));
        $all = $history->all();
        $this->assertSame(['guzzlehttp/guzzle' => '7.15.5'], $all[0]['composer_vuln_versions']);
        $this->assertSame('7.15.5', UpdateReportBuilder::build($all[0], $all[1])['vulns']['composer_resolved_packages'][0]['to']);
    }

    /**
     * A direct dependency's old version is in every snapshot's package list,
     * so it shows even against a snapshot from before vuln versions were kept.
     */
    public function test_a_direct_dependency_falls_back_to_the_package_list(): void
    {
        $row = UpdateReportBuilder::build(
            ['composer_vulns' => 0, 'composer_vuln_packages' => [], 'composer_vuln_versions' => [], 'composer_packages' => ['guzzlehttp/guzzle' => '7.15.5']],
            ['composer_vulns' => 9, 'composer_vuln_packages' => ['guzzlehttp/guzzle' => 9], 'composer_packages' => ['guzzlehttp/guzzle' => '7.10.0']]
        )['vulns']['composer_resolved_packages'][0];

        $this->assertSame(['7.10.0', '7.15.5', false], [$row['from'], $row['to'], $row['removed']]);
    }

    /**
     * Fixed packages sit in the package lists as "(security update)", direct
     * or indirect, with no separate resolved list.
     */
    public function test_fixed_packages_are_security_updates_in_the_package_lists(): void
    {
        $this->app['view']->addNamespace('statamic-sentinel', __DIR__ . '/../../resources/views');

        $report = UpdateReportBuilder::build(
            ['recorded_at' => '2026-10-09T07:09:00+00:00', 'composer_vulns' => 0, 'composer_vuln_packages' => [], 'composer_packages' => ['guzzlehttp/guzzle' => '7.15.5', 'monolog/monolog' => '3.1.0'],
             'composer_vuln_versions' => ['guzzlehttp/guzzle' => '7.15.5', 'symfony/mime' => '8.1.7'], 'npm_vulns' => 0, 'npm_vuln_packages' => [], 'npm_vuln_versions' => ['plyr' => null, 'nanoid' => '3.3.8']],
            ['recorded_at' => '2026-10-01T07:09:00+00:00', 'composer_vulns' => 11, 'composer_vuln_packages' => ['guzzlehttp/guzzle' => 9, 'symfony/mime' => 2], 'composer_packages' => ['guzzlehttp/guzzle' => '7.10.0', 'monolog/monolog' => '3.0.0'],
             'composer_vuln_versions' => ['guzzlehttp/guzzle' => '7.10.0', 'symfony/mime' => '8.1.2'], 'npm_vulns' => 4, 'npm_vuln_packages' => ['plyr' => 1, 'nanoid' => 3], 'npm_vuln_versions' => ['plyr' => '3.7.8', 'nanoid' => '3.3.7']]
        );

        $this->assertSame(['guzzlehttp/guzzle', 'monolog/monolog', 'symfony/mime'], array_column($report['composer']['updated'], 'name'));
        $this->assertSame([true, false, true], array_map(fn ($r) => ! empty($r['security']), $report['composer']['updated']));

        $html = preg_replace('/\s+/', ' ', (string) view('statamic-sentinel::emails.update-report', [
            'report' => $report, 'host' => 'example.test', 'hosts' => ['example.test'], 'preheader' => 'Update',
        ]));

        $this->assertMatchesRegularExpression('#guzzlehttp/guzzle <span[^>]*>\(security update\)</span></td> <td[^>]*> 7\.10\.0 <span[^>]*>→</span> <strong[^>]*>7\.15\.5</strong>#', $html);
        $this->assertMatchesRegularExpression('#symfony/mime <span[^>]*>\(security update\)</span></td> <td[^>]*> 8\.1\.2 <span[^>]*>→</span> <strong[^>]*>8\.1\.7</strong>#', $html);
        $this->assertMatchesRegularExpression('#monolog/monolog</td>#', $html);
        $this->assertMatchesRegularExpression('#plyr <span[^>]*>\(security update\)</span></td> <td[^>]*> removed \(was 3\.7\.8\)#', $html);
        $this->assertStringContainsString('nanoid <span', $html);

        // Nothing outstanding, so no Vulnerabilities section.
        $this->assertStringNotContainsString('>Vulnerabilities</div>', $html);
    }

    public function test_a_fix_without_a_version_change_is_not_listed_as_an_update(): void
    {
        $report = UpdateReportBuilder::build(
            ['composer_vulns' => 0, 'composer_vuln_packages' => [], 'composer_vuln_versions' => ['acme/pkg' => '1.0.0']],
            ['composer_vulns' => 1, 'composer_vuln_packages' => ['acme/pkg' => 1], 'composer_vuln_versions' => ['acme/pkg' => '1.0.0']]
        );

        $this->assertSame([], $report['composer']['updated']);
        $this->assertSame(1, $report['vulns']['composer_resolved']);
    }

    protected function legacy(array $composerVulns): array
    {
        $snapshot = (fn () => $this->buildSnapshot(...func_get_args()))->call(new HistoryService, $this->audit($composerVulns, []));
        unset($snapshot['composer_vuln_versions'], $snapshot['npm_vuln_versions']);

        return $snapshot;
    }

    protected function writeLocks(array $composer, array $npm): void
    {
        file_put_contents(base_path('composer.lock'), json_encode(['packages' => array_map(
            fn ($name, $version) => ['name' => $name, 'version' => $version],
            array_keys($composer), $composer
        )]));

        $packages = [];
        foreach ($npm as $name => $versions) {
            foreach ($versions as $i => $version) {
                $packages[($i ? "node_modules/x{$i}/" : '') . "node_modules/{$name}"] = ['version' => $version];
            }
        }
        file_put_contents(base_path('package-lock.json'), json_encode(['lockfileVersion' => 3, 'packages' => $packages]));
    }

    protected function audit(array $composerVulns, array $npmVulns): array
    {
        $eco = fn (array $vulns) => [
            'status'      => $vulns ? 'vulnerable' : 'ok',
            'total_vulns' => array_sum($vulns),
            'by_package'  => array_map(fn ($n, $c) => ['name' => $n, 'count' => $c, 'highest' => 'HIGH'], array_keys($vulns), $vulns),
            'outdated'    => ['total' => 0, 'security_updates_total' => 0],
        ];

        return [
            'statamic' => ['current' => '6.0.0'],
            'laravel'  => ['version' => '13.0.0'],
            'php'      => ['version' => '8.4.0'],
            'composer' => $eco($composerVulns),
            'npm'      => $eco($npmVulns),
        ];
    }
}
