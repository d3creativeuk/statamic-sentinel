<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Http\Controllers\SentinelController;
use D3Creative\Sentinel\Mail\SentinelUpdateReport;
use D3Creative\Sentinel\Services\HistoryService;
use D3Creative\Sentinel\Services\PackageNoteService;
use D3Creative\Sentinel\Services\UpdateReportBuilder;
use D3Creative\Sentinel\Tests\Support\RegistersViews;
use D3Creative\Sentinel\Tests\Support\ViewTestUser;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Some vulnerable dependencies can't be updated straight away (braces is
 * stuck behind Tailwind v3). The update report names the direct dependency
 * that pulls each one in, and shows any note saved on it, so the client gets
 * the reason rather than just a red count.
 */
class PackageNotesTest extends TestCase
{
    use RegistersViews;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array', 'app.url' => 'https://example.test']);
        Storage::fake('local');
        $this->registerViews();
    }

    public function test_new_entries_take_their_parent_from_the_latest_snapshot_and_resolved_from_the_previous(): void
    {
        $report = UpdateReportBuilder::build(
            $this->snapshot(['braces' => 1], ['braces' => 'tailwindcss']),
            $this->snapshot(['old-pkg' => 2], ['old-pkg' => 'vite'])
        );

        $this->assertSame(
            [['name' => 'braces', 'count' => 1, 'parent' => 'tailwindcss', 'ecosystem' => 'npm']],
            $report['vulns']['npm_introduced_packages']
        );
        $this->assertSame(
            [['name' => 'old-pkg', 'count' => 2, 'parent' => 'vite', 'ecosystem' => 'npm']],
            $report['vulns']['npm_resolved_packages']
        );
    }

    public function test_snapshots_without_parents_give_no_parent(): void
    {
        $report = UpdateReportBuilder::build($this->snapshot(['braces' => 1]), $this->snapshot([]));

        $this->assertNull($report['vulns']['npm_introduced_packages'][0]['parent']);
    }

    public function test_a_fix_and_a_new_issue_in_one_ecosystem_dont_cancel_out(): void
    {
        $report = UpdateReportBuilder::build($this->snapshot(['braces' => 1]), $this->snapshot(['old-pkg' => 1]));

        $this->assertSame(1, $report['vulns']['npm_resolved']);
        $this->assertSame(1, $report['vulns']['npm_introduced']);
        $this->assertTrue($report['has_changes']);
    }

    public function test_snapshots_without_per_package_maps_fall_back_to_the_totals(): void
    {
        $report = UpdateReportBuilder::build(['npm_vulns' => 5], ['npm_vulns' => 2]);

        $this->assertSame(3, $report['vulns']['npm_introduced']);
        $this->assertSame(0, $report['vulns']['npm_resolved']);
        $this->assertSame(0, $report['vulns']['npm_open']);
    }

    public function test_issues_that_carry_over_are_listed_as_still_open(): void
    {
        $report = UpdateReportBuilder::build(
            $this->snapshot(['braces' => 1, 'postcss-selector-parser' => 1], ['braces' => 'tailwindcss', 'postcss-selector-parser' => '@tailwindcss/typography']),
            $this->snapshot(['braces' => 1, 'postcss-selector-parser' => 1, 'source-map-js' => 1])
        );

        $this->assertSame(1, $report['vulns']['npm_resolved']);
        $this->assertSame(0, $report['vulns']['npm_introduced']);
        $this->assertSame(2, $report['vulns']['npm_open']);
        $this->assertSame([
            ['name' => 'braces', 'count' => 1, 'parent' => 'tailwindcss', 'ecosystem' => 'npm'],
            ['name' => 'postcss-selector-parser', 'count' => 1, 'parent' => '@tailwindcss/typography', 'ecosystem' => 'npm'],
        ], $report['vulns']['npm_open_packages']);
    }

    public function test_still_open_issues_alone_are_not_a_change(): void
    {
        $report = UpdateReportBuilder::build($this->snapshot(['braces' => 1]), $this->snapshot(['braces' => 1]));

        $this->assertSame(1, $report['vulns']['npm_open']);
        $this->assertFalse($report['has_changes']);
    }

    public function test_a_new_advisory_on_a_package_with_an_old_one_is_both_new_and_still_open(): void
    {
        $report = UpdateReportBuilder::build($this->snapshot(['braces' => 2]), $this->snapshot(['braces' => 1]));

        $this->assertSame(1, $report['vulns']['npm_introduced']);
        $this->assertSame(1, $report['vulns']['npm_open']);
    }

    public function test_the_update_report_shows_notes_for_still_open_issues(): void
    {
        (new PackageNoteService)->set('npm', 'tailwindcss', "Braces can't be updated until Tailwind 3 is.");

        $report = UpdateReportBuilder::build(
            $this->snapshot(['braces' => 1, 'postcss-selector-parser' => 1], ['braces' => 'tailwindcss', 'postcss-selector-parser' => '@tailwindcss/typography']),
            $this->snapshot(['braces' => 1, 'postcss-selector-parser' => 1, 'source-map-js' => 1])
        );

        $html = (new SentinelUpdateReport($report))->render();

        $this->assertStringContainsString('1 resolved', $html);
        $this->assertStringContainsString('2 still open', $html);
        $this->assertStringContainsString('braces via tailwindcss', $html);
        $this->assertStringContainsString('postcss-selector-parser via @tailwindcss/typography', $html);
        $this->assertStringContainsString('tailwindcss:</strong> Braces can&#039;t be updated until Tailwind 3 is.', $html);
    }

    public function test_a_report_stored_before_parents_and_still_open_existed_renders(): void
    {
        (new PackageNoteService)->set('npm', 'braces', 'Build-time only.');

        $report = UpdateReportBuilder::build($this->snapshot(['braces' => 1]), $this->snapshot([]));
        unset($report['vulns']['composer_open'], $report['vulns']['npm_open'], $report['vulns']['composer_open_packages'], $report['vulns']['npm_open_packages']);
        $report['vulns']['npm_introduced_packages'] = [['name' => 'braces', 'count' => 1]];

        $html = (new SentinelUpdateReport($report))->render();

        $this->assertStringContainsString('1 new', $html);
        $this->assertStringContainsString('braces</div>', $html);
        $this->assertStringNotContainsString('still open', $html);
    }

    public function test_history_stores_parents_without_them_driving_a_new_snapshot(): void
    {
        $history = new HistoryService;

        $history->recordIfChanged($this->audit(['braces' => 'tailwindcss']));
        $history->recordIfChanged($this->audit(['braces' => 'postcss']));

        $all = $history->all();
        $this->assertCount(1, $all);
        $this->assertSame(['braces' => 'tailwindcss'], $all[0]['npm_dependency_parents']);
    }

    public function test_a_failed_vuln_check_carries_parents_forward(): void
    {
        $history = new HistoryService;

        $history->recordIfChanged($this->audit(['braces' => 'tailwindcss']));
        $history->recordIfChanged($this->audit([], 'error', '6.1.0'));

        $this->assertSame(['braces' => 'tailwindcss'], $history->all()[0]['npm_dependency_parents']);
    }

    public function test_a_snapshot_from_before_parents_were_stored_gets_them_on_the_next_scan(): void
    {
        $history = new HistoryService;
        $history->recordIfChanged($this->audit(['braces' => 'tailwindcss']));

        $legacy = $history->all();
        unset($legacy[0]['composer_dependency_parents'], $legacy[0]['npm_dependency_parents']);
        Storage::disk('local')->put(HistoryService::RELATIVE_PATH, json_encode($legacy));

        // A failed vuln check has nothing reliable to fill in.
        $history->recordIfChanged($this->audit(['braces' => 'tailwindcss'], 'error'));
        $this->assertArrayNotHasKey('npm_dependency_parents', $history->all()[0]);

        $history->recordIfChanged($this->audit(['braces' => 'tailwindcss']));

        $all = $history->all();
        $this->assertCount(1, $all);
        $this->assertSame(['braces' => 'tailwindcss'], $all[0]['npm_dependency_parents']);
        $this->assertSame($legacy[0]['id'], $all[0]['id']);
    }

    public function test_notes_save_update_and_remove(): void
    {
        $notes = new PackageNoteService;

        $this->assertTrue($notes->set('npm', 'tailwindcss', 'Stuck on v3', '1'));
        $this->assertTrue($notes->set('npm', 'tailwindcss', 'Waiting for a v3 patch', '1'));
        $this->assertSame('Waiting for a v3 patch', $notes->all()['npm']['tailwindcss']['note']);
        $this->assertSame('1', $notes->all()['npm']['tailwindcss']['updated_by']);

        $this->assertTrue($notes->set('npm', 'tailwindcss', '  '));
        $this->assertSame(['composer' => [], 'npm' => []], $notes->all());
    }

    public function test_a_corrupt_notes_file_reads_as_empty(): void
    {
        Storage::disk('local')->put(PackageNoteService::RELATIVE_PATH, '{not json');

        $this->assertSame(['composer' => [], 'npm' => []], (new PackageNoteService)->all());
    }

    public function test_a_parent_note_comes_before_the_package_note(): void
    {
        $notes = ['npm' => ['tailwindcss' => ['note' => 'Parent'], 'braces' => ['note' => 'Own']]];

        $this->assertSame(
            ['tailwindcss' => 'Parent', 'braces' => 'Own'],
            PackageNoteService::forEntry($notes, ['name' => 'braces', 'parent' => 'tailwindcss', 'ecosystem' => 'npm'])
        );
        $this->assertSame([], PackageNoteService::forEntry($notes, ['name' => 'braces', 'parent' => null, 'ecosystem' => 'composer']));
    }

    public function test_the_controller_saves_and_validates_a_note(): void
    {
        $this->actingAs(new ViewTestUser(true));

        $saved = $this->saveNote(['ecosystem' => 'npm', 'package' => '@scope/tailwind-plugin', 'note' => 'Hello']);
        $this->assertSame(200, $saved->getStatusCode());
        $this->assertSame('Hello', (new PackageNoteService)->all()['npm']['@scope/tailwind-plugin']['note']);

        $this->assertSame(422, $this->saveNote(['ecosystem' => 'pip', 'package' => 'x', 'note' => 'y'])->getStatusCode());
        $this->assertSame(422, $this->saveNote(['ecosystem' => 'npm', 'package' => '../etc', 'note' => 'y'])->getStatusCode());
        $this->assertSame(422, $this->saveNote(['ecosystem' => 'npm', 'package' => 'x', 'note' => str_repeat('a', 1001)])->getStatusCode());
    }

    public function test_the_update_report_names_the_parent_and_shows_its_note(): void
    {
        (new PackageNoteService)->set('npm', 'tailwindcss', "Stuck on Tailwind v3.\nWaiting for a patch <b>");

        $report = UpdateReportBuilder::build(
            $this->snapshot(['braces' => 1, 'postcss-selector-parser' => 1, 'source-map-js' => 1], ['braces' => 'tailwindcss', 'postcss-selector-parser' => 'tailwindcss']),
            $this->snapshot([])
        );

        $html = (new SentinelUpdateReport($report))->render();

        $this->assertStringContainsString('source-map-js</div>', $html);
        $this->assertStringContainsString('braces, postcss-selector-parser via tailwindcss', $html);
        $this->assertStringContainsString('tailwindcss:</strong> Stuck on Tailwind v3.<br />', $html);
        $this->assertStringContainsString('Waiting for a patch &lt;b&gt;', $html);
    }

    public function test_the_utility_shows_a_saved_note_and_escapes_it(): void
    {
        $this->actingAs(new ViewTestUser(true));

        $html = $this->renderUtility(['acme/pkg' => ['note' => '{{ 7*7 }} <script>x</script> "quoted"']]);

        $this->assertStringContainsString('Edit note', $html);
        $this->assertStringContainsString('{{ 7*7 }} &lt;script&gt;x&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('aria-label="Note on acme/pkg"', $html);
    }

    public function test_non_supers_see_notes_but_no_editor(): void
    {
        $this->actingAs(new ViewTestUser(false));

        $html = $this->renderUtility(['acme/pkg' => ['note' => 'Visible note']]);

        $this->assertStringContainsString('Visible note', $html);
        $this->assertStringNotContainsString('Edit note', $html);
        $this->assertStringNotContainsString('save-package-note', $html);
    }

    protected function saveNote(array $input)
    {
        return $this->app->call([$this->app->make(SentinelController::class), 'savePackageNote'], [
            'request' => Request::create('/', 'POST', $input),
        ]);
    }

    protected function snapshot(array $vulnPackages, array $parents = []): array
    {
        return [
            'npm_vulns'              => array_sum($vulnPackages),
            'npm_vuln_packages'      => $vulnPackages,
            'npm_dependency_parents' => $parents,
        ];
    }

    protected function audit(array $parents, string $npmStatus = 'vulnerable', string $statamic = '6.0.0'): array
    {
        return [
            'statamic' => ['current' => $statamic],
            'laravel'  => ['version' => '13.0.0'],
            'php'      => ['version' => '8.4.0'],
            'composer' => ['status' => 'ok', 'total_vulns' => 0, 'outdated' => ['total' => 0, 'security_updates_total' => 0]],
            'npm'      => [
                'status'             => $npmStatus,
                'total_vulns'        => 1,
                'by_package'         => [['name' => 'braces', 'count' => 1, 'highest' => 'HIGH']],
                'dependency_parents' => $parents,
                'outdated'           => ['total' => 0, 'security_updates_total' => 0],
            ],
        ];
    }

    protected function renderUtility(array $composerNotes): string
    {
        $eco = [
            'status'             => 'vulnerable',
            'total_packages'     => 10,
            'total_vulns'        => 1,
            'counts'             => ['CRITICAL' => 0, 'HIGH' => 1, 'MEDIUM' => 0, 'LOW' => 0, 'UNKNOWN' => 0],
            'severities'         => [],
            'by_package'         => [['name' => 'acme/pkg', 'highest' => 'HIGH', 'count' => 1, 'vulns' => [['id' => 'GHSA-aaaa', 'cve' => 'CVE-2026-0001', 'severity' => 'HIGH', 'url' => 'https://osv.dev/vulnerability/GHSA-aaaa']]]],
            'dependency_parents' => [],
            'outdated'           => ['total' => 0, 'security_updates_total' => 0, 'packages' => []],
            'installed'          => [],
        ];

        return (string) view('statamic-sentinel::utilities.sentinel', [
            'audit'                       => [
                'statamic'   => ['current' => '6.0.0', 'latest' => '6.0.0', 'is_latest' => true, 'status' => 'ok', 'security_update_available' => false, 'security_source' => null],
                'laravel'    => ['version' => '13.0.0', 'latest' => '13.0.0', 'is_latest' => true, 'status' => 'active', 'label' => 'Active Support', 'security_update_available' => false],
                'php'        => ['version' => '8.4.0', 'latest' => '8.4.0', 'is_latest' => true, 'status' => 'active', 'label' => 'Active Support'],
                'composer'   => $eco,
                'npm'        => array_replace($eco, ['status' => 'ok', 'total_vulns' => 0, 'by_package' => []]),
                'audited_at' => '1 Oct 2026, 09:00',
            ],
            'history'                     => [],
            'schedule'                    => ['status_report' => ['enabled' => false, 'frequency' => 'daily', 'day_of_week' => 1, 'day_of_month' => 1, 'time' => '09:00', 'recipients' => []]],
            'sent_status'                 => [],
            'sent_update'                 => [],
            'sent_maintenance'            => [],
            'last_status_recipients'      => [],
            'last_update_recipients'      => [],
            'last_maintenance_recipients' => [],
            'maintenance_plan'            => [],
            'package_notes'               => ['composer' => $composerNotes, 'npm' => []],
            'users'                       => [],
            'online_window'               => 5,
            'freeze'                      => new \D3Creative\Sentinel\Services\ContentFreezeService,
            'freeze_current'              => null,
            'freeze_history'              => [],
        ]);
    }
}
