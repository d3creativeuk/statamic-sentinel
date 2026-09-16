<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Services\AuditService;
use D3Creative\Sentinel\Services\HistoryService;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;

/**
 * A failed lookup must never read as a clean site: not in the cached audit,
 * and not in history, where zeros would show as "all resolved" in the update
 * and plan reports.
 */
class FailedScanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Storage::fake('local');
    }

    public function test_an_osv_rate_limit_is_a_failed_check_not_a_clean_result(): void
    {
        Http::fake(['api.osv.dev/v1/querybatch' => Http::response('Too Many Requests', 429)]);

        $result = $this->queryOsv(1);

        $this->assertSame('error', $result['status']);
    }

    public function test_a_failure_in_a_later_chunk_fails_the_whole_check(): void
    {
        $calls = 0;

        Http::fake([
            'api.osv.dev/v1/querybatch' => function () use (&$calls) {
                return ++$calls === 1
                    ? Http::response(['results' => []])
                    : Http::response('Service Unavailable', 503);
            },
        ]);

        // 501 queries = two chunks of 500 and 1.
        $result = $this->queryOsv(501);

        $this->assertSame(2, $calls);
        $this->assertSame('error', $result['status']);
    }

    public function test_history_keeps_previous_figures_for_a_failed_check(): void
    {
        $history = new HistoryService;

        $history->recordIfChanged($this->audit());
        $history->recordIfChanged($this->audit(['composer_status' => 'error', 'composer_vulns' => 0, 'npm_outdated_error' => true, 'npm_outdated' => 0]));

        // Nothing actually changed, so no new row.
        $this->assertCount(1, $history->all());

        // A real change alongside a failed check keeps the old figures.
        $history->recordIfChanged($this->audit(['statamic' => '6.1.0', 'composer_status' => 'error', 'composer_vulns' => 0]));

        $latest = $history->all()[0];
        $this->assertCount(2, $history->all());
        $this->assertSame('6.1.0', $latest['statamic']);
        $this->assertSame(12, $latest['composer_vulns']);
        $this->assertSame(['acme/pkg' => 12], $latest['composer_vuln_packages']);
        $this->assertSame(4, $latest['npm_outdated']);
    }

    public function test_history_records_nothing_when_a_first_scan_fails(): void
    {
        $history = new HistoryService;

        $history->recordIfChanged($this->audit(['composer_status' => 'error', 'composer_vulns' => 0]));

        $this->assertSame([], $history->all());
    }

    protected function queryOsv(int $packages): array
    {
        $queries = array_fill(0, $packages, [
            'package' => ['name' => 'acme/pkg', 'ecosystem' => 'Packagist'],
            'version' => '1.0.0',
        ]);

        $service = new AuditService;
        $method  = new ReflectionMethod($service, 'queryOsv');
        $method->setAccessible(true);

        return $method->invoke($service, $queries, $packages);
    }

    protected function audit(array $o = []): array
    {
        $vulns = $o['composer_vulns'] ?? 12;

        return [
            'statamic' => ['current' => $o['statamic'] ?? '6.0.0'],
            'laravel'  => ['version' => '13.0.0'],
            'php'      => ['version' => '8.4.0'],
            'composer' => [
                'status'      => $o['composer_status'] ?? 'vulnerable',
                'total_vulns' => $vulns,
                'by_package'  => $vulns ? [['name' => 'acme/pkg', 'count' => $vulns, 'highest' => 'HIGH']] : [],
                'outdated'    => ['total' => 2, 'security_updates_total' => 1],
            ],
            'npm' => [
                'status'      => 'ok',
                'total_vulns' => 0,
                'outdated'    => array_filter([
                    'total'                  => $o['npm_outdated'] ?? 4,
                    'security_updates_total' => 0,
                    'error'                  => $o['npm_outdated_error'] ?? null,
                ], fn ($v) => $v !== null),
            ],
        ];
    }
}
