<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Services\HistoryService;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

/**
 * A scan is recorded when anything a report shows has changed, not only the
 * totals. A one-for-one vulnerability swap, or updates that stay behind the
 * same newer major, used to leave every total equal, record nothing, and
 * leave the update report re-sending the previous diff.
 */
class HistoryChangeDetectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_a_vulnerable_package_swap_with_the_same_totals_is_recorded(): void
    {
        $history = new HistoryService;

        $history->recordIfChanged($this->audit(['braces' => 'HIGH'], ['tailwindcss' => '3.4.0']));
        $history->recordIfChanged($this->audit(['postcss' => 'HIGH'], ['tailwindcss' => '3.4.0']));

        $this->assertCount(2, $history->all());
        $this->assertSame(['postcss' => 1], $history->all()[0]['npm_vuln_packages']);
    }

    public function test_a_version_update_with_the_same_totals_is_recorded(): void
    {
        $history = new HistoryService;

        $history->recordIfChanged($this->audit([], ['tailwindcss' => '3.4.0']));
        $history->recordIfChanged($this->audit([], ['tailwindcss' => '3.4.17']));

        $this->assertCount(2, $history->all());
    }

    public function test_identical_or_reordered_scans_record_nothing(): void
    {
        $history = new HistoryService;

        $history->recordIfChanged($this->audit([], ['a' => '1.0.0', 'b' => '2.0.0']));
        $history->recordIfChanged($this->audit([], ['b' => '2.0.0', 'a' => '1.0.0']));
        $history->recordIfChanged($this->audit([], ['a' => '1.0.0', 'b' => '2.0.0']));

        $this->assertCount(1, $history->all());
    }

    public function test_numeric_looking_versions_are_compared_exactly(): void
    {
        $history = new HistoryService;

        $history->recordIfChanged($this->audit([], ['a' => '1.1']));
        $history->recordIfChanged($this->audit([], ['a' => '1.10']));

        $this->assertCount(2, $history->all());
    }

    public function test_a_snapshot_from_before_the_maps_existed_is_not_a_change(): void
    {
        $history = new HistoryService;
        $history->recordIfChanged($this->audit([], ['a' => '1.0.0']));

        $entries = $history->all();
        unset($entries[0]['npm_packages'], $entries[0]['npm_vuln_packages'], $entries[0]['composer_packages'], $entries[0]['composer_vuln_packages']);
        Storage::disk('local')->put(HistoryService::RELATIVE_PATH, json_encode($entries));

        $history->recordIfChanged($this->audit([], ['a' => '1.0.0']));

        $this->assertCount(1, (new HistoryService)->all());
    }

    /**
     * @param array<string, string> $vulnerable package => highest severity
     * @param array<string, string> $npmInstalled
     */
    protected function audit(array $vulnerable, array $npmInstalled): array
    {
        $byPackage = [];
        foreach ($vulnerable as $name => $severity) {
            $byPackage[] = ['name' => $name, 'count' => 1, 'highest' => $severity];
        }

        return [
            'statamic' => ['current' => '6.0.0'],
            'laravel'  => ['version' => '12.0.0'],
            'php'      => ['version' => '8.4.0'],
            'composer' => ['status' => 'ok', 'total_vulns' => 0, 'installed' => ['statamic/cms' => '6.0.0'], 'outdated' => ['total' => 0]],
            'npm'      => [
                'status'      => $vulnerable ? 'vulnerable' : 'ok',
                'total_vulns' => count($vulnerable),
                'by_package'  => $byPackage,
                'installed'   => $npmInstalled,
                'outdated'    => ['total' => 0],
            ],
        ];
    }
}
