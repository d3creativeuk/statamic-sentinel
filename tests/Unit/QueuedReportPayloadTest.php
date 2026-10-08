<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Mail\SentinelReport;
use D3Creative\Sentinel\Services\SentMailService;
use D3Creative\Sentinel\Tests\Support\RegistersViews;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

/**
 * The status email is queued, and its job used to carry the whole audit
 * twice: once as the mailable's property and again in the view data that
 * rendering it for the sent log had filled in.
 */
class QueuedReportPayloadTest extends TestCase
{
    use RegistersViews;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->registerViews();
    }

    public function test_the_slim_audit_renders_exactly_the_same_email(): void
    {
        $full = $this->audit();

        $fromFull = (string) view('statamic-sentinel::emails.report', ['audit' => $full, 'host' => 'a.test', 'hosts' => ['a.test'], 'preheader' => 'x']);
        $fromSlim = (string) view('statamic-sentinel::emails.report', ['audit' => SentinelReport::slim($full), 'host' => 'a.test', 'hosts' => ['a.test'], 'preheader' => 'x']);

        $this->assertSame($fromFull, $fromSlim);
    }

    public function test_the_queued_mailable_carries_only_the_slim_audit(): void
    {
        $mailable = new SentinelReport($this->audit());
        (clone $mailable)->render();

        $this->assertArrayNotHasKey('severities', $mailable->audit['composer']);
        $this->assertArrayNotHasKey('packages', $mailable->audit['composer']['outdated']);
        $this->assertSame([], $mailable->viewData);
        $this->assertLessThan(strlen(serialize($this->audit())) / 4, strlen(serialize($mailable)));
    }

    public function test_stored_send_errors_are_capped(): void
    {
        $sent = new SentMailService;
        $id   = $sent->record(SentMailService::KIND_STATUS, ['a@b.test'], 'manual', SentMailService::OUTCOME_FAILED, '', str_repeat('SQL bindings ', 20000));

        $this->assertLessThanOrEqual(503, mb_strlen((new SentMailService)->find($id)['error']));
    }

    protected function audit(): array
    {
        $vulns = [];
        for ($i = 0; $i < 100; $i++) {
            $vulns[] = ['id' => "GHSA-{$i}", 'cve' => "CVE-2026-{$i}", 'severity' => 'HIGH', 'package' => "p/{$i}", 'summary' => str_repeat('Long summary ', 10), 'fix_available' => true, 'url' => "https://osv.dev/vulnerability/GHSA-{$i}"];
        }

        $eco = [
            'status'         => 'vulnerable',
            'total_packages' => 100,
            'total_vulns'    => 100,
            'counts'         => ['CRITICAL' => 0, 'HIGH' => 100, 'MEDIUM' => 0, 'LOW' => 0, 'UNKNOWN' => 0],
            'severities'     => ['HIGH' => ['count' => 100, 'packages' => array_column($vulns, 'package'), 'vulns' => $vulns]],
            'by_package'     => array_map(fn ($v) => ['name' => $v['package'], 'highest' => 'HIGH', 'count' => 1, 'vulns' => [$v]], $vulns),
            'installed'      => array_fill_keys(array_column($vulns, 'package'), '1.0.0'),
            'outdated'       => ['total' => 3, 'security_updates_total' => 1, 'vendor_security_updates_total' => 0, 'packages' => [['name' => 'p/1', 'current' => '1.0.0', 'latest' => '2.0.0']]],
        ];

        return [
            'statamic'   => ['current' => '6.0.0', 'latest' => '6.1.0', 'status' => 'outdated', 'security_update_available' => true, 'releases_behind' => 3],
            'laravel'    => ['version' => '12.0.0', 'latest' => '12.1.0', 'status' => 'active', 'security_update_available' => false],
            'php'        => ['version' => '8.4.0', 'latest' => '8.4.5', 'status' => 'active'],
            'license'    => ['supported' => true, 'status' => 'ok'],
            'composer'   => $eco,
            'npm'        => $eco,
            'audited_at' => '8 Oct 2026, 09:00',
        ];
    }
}
