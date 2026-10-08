<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Services\AuditService;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;

/**
 * OSV advisory summaries are cached across scans and only refetched when an
 * advisory is new or its querybatch `modified` timestamp has moved.
 *
 * @see AuditService::vulnSummaries()
 */
class AuditServiceOsvCacheTest extends TestCase
{
    /** querybatch result per scan: [id => modified] */
    protected array $reported = [];

    protected int $detailStatus = 200;

    /** Raw 200 body to send instead of a real detail record / querybatch answer. */
    protected $detailBody = null;
    protected $batchBody = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);

        Http::fake([
            'api.osv.dev/v1/querybatch' => fn () => $this->batchBody !== null ? Http::response($this->batchBody) : Http::response(['results' => [[
                'vulns' => array_map(
                    fn ($id, $modified) => ['id' => $id, 'modified' => $modified],
                    array_keys($this->reported),
                    array_values($this->reported)
                ),
            ]]]),
            'api.osv.dev/v1/vulns/*' => function ($request) {
                $id = basename($request->url());

                if ($this->detailBody !== null) {
                    return Http::response($this->detailBody);
                }

                return $this->detailStatus === 200
                    ? Http::response([
                        'id'                => $id,
                        'summary'           => "Summary of {$id} at {$this->reported[$id]}",
                        'aliases'           => ['CVE-2026-0001'],
                        'database_specific' => ['severity' => 'HIGH'],
                        'affected'          => [['ranges' => [['events' => [['introduced' => '0'], ['fixed' => '2.0.0']]]]]],
                    ])
                    : Http::response('', $this->detailStatus);
            },
        ]);
    }

    public function test_unchanged_advisories_are_not_refetched_on_the_next_scan(): void
    {
        $this->reported = ['GHSA-aaaa' => '2026-09-01T00:00:00Z'];

        $first  = $this->scan();
        $second = $this->scan();

        $this->assertSame(1, $this->detailRequests());
        $this->assertSame($first, $second);
        $this->assertSame('HIGH', $second['severities']['HIGH']['vulns'][0]['severity']);
        $this->assertSame('CVE-2026-0001', $second['severities']['HIGH']['vulns'][0]['cve']);
        $this->assertTrue($second['severities']['HIGH']['vulns'][0]['fix_available']);
    }

    public function test_a_modified_advisory_is_refetched(): void
    {
        $this->reported = ['GHSA-aaaa' => '2026-09-01T00:00:00Z'];
        $this->scan();

        $this->reported = ['GHSA-aaaa' => '2026-09-10T00:00:00Z'];
        $result = $this->scan();

        $this->assertSame(2, $this->detailRequests());
        $this->assertSame(
            'Summary of GHSA-aaaa at 2026-09-10T00:00:00Z',
            $result['severities']['HIGH']['vulns'][0]['summary']
        );
    }

    public function test_a_failed_detail_lookup_is_not_cached(): void
    {
        $this->reported     = ['GHSA-aaaa' => '2026-09-01T00:00:00Z'];
        $this->detailStatus = 500;

        $failed = $this->scan();
        $this->assertSame(1, $failed['counts']['UNKNOWN']);

        $this->detailStatus = 200;
        $recovered = $this->scan();

        $this->assertSame(2, $this->detailRequests());
        $this->assertSame(1, $recovered['counts']['HIGH']);
    }

    /**
     * A 200 that isn't the record asked for (cut off mid-transfer, a proxy
     * page, another advisory) used to be cached as UNKNOWN with no fix until
     * OSV next changed the advisory.
     */
    public function test_a_detail_body_that_isnt_the_requested_record_is_not_cached(): void
    {
        $this->reported = ['GHSA-aaaa' => '2026-09-01T00:00:00Z'];

        foreach (['<html>Gateway</html>', '{"id":"GHSA-aaaa","summ', ['id' => 'GHSA-other'], 'oops'] as $body) {
            $this->detailBody = $body;
            $this->assertSame(1, $this->scan()['counts']['UNKNOWN']);
        }

        $this->detailBody = null;
        $recovered = $this->scan();

        $this->assertSame(1, $recovered['counts']['HIGH']);
        $this->assertTrue($recovered['severities']['HIGH']['vulns'][0]['fix_available']);
    }

    public function test_a_querybatch_body_without_results_fails_the_check(): void
    {
        $this->reported = ['GHSA-aaaa' => '2026-09-01T00:00:00Z'];

        $this->batchBody = '<html>Please wait while we check your browser</html>';
        $this->assertSame('error', $this->scan()['status']);

        $this->batchBody = ['message' => 'ok'];
        $this->assertSame('error', $this->scan()['status']);
    }

    public function test_paged_results_fail_the_check_rather_than_undercount(): void
    {
        $this->batchBody = ['results' => [['vulns' => [['id' => 'GHSA-aaaa', 'modified' => '2026-09-01T00:00:00Z']], 'next_page_token' => 'abc']]];
        $this->reported  = ['GHSA-aaaa' => '2026-09-01T00:00:00Z'];

        $this->assertSame('error', $this->scan()['status']);
    }

    public function test_advisories_no_longer_reported_are_pruned(): void
    {
        $this->reported = ['GHSA-aaaa' => '2026-09-01T00:00:00Z', 'GHSA-bbbb' => '2026-09-01T00:00:00Z'];
        $this->scan();

        $this->reported = ['GHSA-bbbb' => '2026-09-01T00:00:00Z'];
        $this->scan(false);
        $this->assertArrayHasKey('GHSA-aaaa', Cache::get(AuditService::OSV_SUMMARY_CACHE_KEY)['summaries']);

        $this->scan(true);
        $this->assertSame(['GHSA-bbbb'], array_keys(Cache::get(AuditService::OSV_SUMMARY_CACHE_KEY)['summaries']));
    }

    public function test_summaries_from_another_schema_or_malformed_hits_are_refetched(): void
    {
        $this->reported = ['GHSA-aaaa' => '2026-09-01T00:00:00Z', 'GHSA-bbbb' => '2026-09-01T00:00:00Z'];

        // Pre-versioning shape (bare map), then a current-schema cache whose
        // entry for bbbb lacks fields.
        Cache::forever(AuditService::OSV_SUMMARY_CACHE_KEY, [
            'GHSA-aaaa' => ['modified' => '2026-09-01T00:00:00Z', 'severity' => 'LOW', 'summary' => 'stale', 'cve' => null, 'fix_available' => false],
        ]);
        $this->scan();
        $this->assertSame(2, $this->detailRequests());

        Cache::forever(AuditService::OSV_SUMMARY_CACHE_KEY, [
            'schema'    => AuditService::OSV_SUMMARY_SCHEMA,
            'summaries' => [
                'GHSA-aaaa' => ['modified' => '2026-09-01T00:00:00Z', 'severity' => 'HIGH', 'summary' => 'ok', 'cve' => null, 'fix_available' => true, 'fixed' => []],
                'GHSA-bbbb' => ['modified' => '2026-09-01T00:00:00Z', 'severity' => 'SEVERE'],
            ],
        ]);
        $result = $this->scan();

        $this->assertSame(3, $this->detailRequests());
        $this->assertSame(2, $result['counts']['HIGH']);
        $this->assertArrayNotHasKey('SEVERE', $result['severities']);
    }

    /**
     * One scan's OSV step on a fresh service instance, as refresh() runs it.
     */
    protected function scan(bool $prune = true): array
    {
        $service = new AuditService;

        $query = new ReflectionMethod($service, 'queryOsv');
        $query->setAccessible(true);
        $result = $query->invoke($service, [[
            'package' => ['name' => 'acme/pkg', 'ecosystem' => 'Packagist'],
            'version' => '1.0.0',
        ]], 1);

        $persist = new ReflectionMethod($service, 'persistVulnSummaries');
        $persist->setAccessible(true);
        $persist->invoke($service, $prune);

        return $result;
    }

    protected function detailRequests(): int
    {
        return Http::recorded(fn ($request) => str_contains($request->url(), '/v1/vulns/'))->count();
    }
}
