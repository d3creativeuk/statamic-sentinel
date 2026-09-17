<?php

namespace D3Creative\Sentinel\Tests\Unit;

use Carbon\Carbon;
use D3Creative\Sentinel\Http\Controllers\FreezeController;
use D3Creative\Sentinel\Http\Controllers\SentinelController;
use D3Creative\Sentinel\Mail\FreezeCompletionMail;
use D3Creative\Sentinel\Mail\FreezeNotificationMail;
use D3Creative\Sentinel\Mail\SentinelMaintenanceReport;
use D3Creative\Sentinel\Mail\SentinelReport;
use D3Creative\Sentinel\Mail\SentinelUpdateReport;
use D3Creative\Sentinel\Services\MaintenanceReportBuilder;
use D3Creative\Sentinel\Services\UpdateReportBuilder;
use D3Creative\Sentinel\Tests\Support\RegistersViews;
use D3Creative\Sentinel\Tests\Support\ViewTestUser;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The test app never loaded the addon's views, so no test rendered an email
 * (SentOutcomeTest's send "passed" with a swallowed render error), and no
 * test checked that non-supers are refused by the CP endpoints.
 */
class EmailAndControllerAccessTest extends TestCase
{
    use RegistersViews;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array', 'app.url' => 'https://example.test']);
        Storage::fake('local');
        $this->registerViews();
    }

    public function test_every_email_renders_with_current_data(): void
    {
        $freeze = [
            'id'                        => 'freeze_abc',
            'notify_at'                 => Carbon::now()->subDay()->toIso8601String(),
            'freeze_at'                 => Carbon::now()->toIso8601String(),
            'freeze_ends_at'            => Carbon::now()->addHours(3)->toIso8601String(),
            'expected_duration_minutes' => 90,
            'completed_at'              => Carbon::now()->addHours(2)->toIso8601String(),
            'recipients'                => ['editor@example.test'],
        ];

        $update = UpdateReportBuilder::build(
            ['recorded_at' => '2026-09-02T09:00:00Z', 'statamic' => '6.1.0', 'php' => '8.4.0', 'composer_packages' => ['a/a' => '2.0.0'], 'npm_packages' => ['x' => '1.1.0'], 'composer_vuln_packages' => []],
            ['recorded_at' => '2026-09-01T09:00:00Z', 'statamic' => '6.0.0', 'php' => '8.3.0', 'composer_packages' => ['a/a' => '1.0.0'], 'npm_packages' => ['x' => '1.0.0'], 'composer_vuln_packages' => ['a/a' => 1]]
        );

        $maintenance = MaintenanceReportBuilder::build([
            ['recorded_at' => '2026-09-02T09:00:00Z', 'statamic' => '6.1.0', 'composer_packages' => ['a/a' => '2.0.0'], 'composer_vuln_packages' => []],
            ['recorded_at' => '2026-09-01T09:00:00Z', 'statamic' => '6.0.0', 'composer_packages' => ['a/a' => '1.0.0'], 'composer_vuln_packages' => ['a/a' => 1], 'composer_vuln_severities' => ['a/a' => 'HIGH']],
        ], ['plan_name' => 'Care Plan', 'start_date' => '2026-08-01']);

        $rendered = [
            'status'       => (new SentinelReport($this->audit()))->render(),
            'update'       => (new SentinelUpdateReport($update))->render(),
            'maintenance'  => (new SentinelMaintenanceReport($maintenance))->render(),
            'notification' => (new FreezeNotificationMail($freeze))->render(),
            'completion'   => (new FreezeCompletionMail($freeze))->render(),
        ];

        foreach ($rendered as $name => $html) {
            $this->assertStringContainsString('example.test', $html, $name);
            $this->assertMatchesRegularExpression('/(generated|sent) by .*\./s', $html, $name);
        }

        $this->assertStringContainsString('Care Plan', $rendered['maintenance']);
        // The security pill leads; the grey note spreads the count across packages.
        $this->assertStringContainsString('1 security issue across 1 package</div>', $rendered['status']);
        $this->assertStringNotContainsString('1 update available', $rendered['status']);

        // "Major version behind" is always the first pill.
        $majorAudit = $this->audit();
        $majorAudit['statamic'] = ['current' => '5.73.2', 'latest' => '6.33.0', 'is_latest' => false, 'status' => 'outdated', 'security_update_available' => true, 'security_source' => 'osv'];
        $majorHtml  = (new SentinelReport($majorAudit))->render();
        $this->assertLessThan(strpos($majorHtml, 'Security update</span>'), strpos($majorHtml, 'Major version behind</span>'));

        // PHP leads with the patch inside its branch and names the newer branch.
        $phpAudit = $this->audit();
        $phpAudit['php'] = ['version' => '8.4.20', 'latest' => '8.5.10', 'status' => 'active', 'label' => 'Active Support', 'branches' => [
            ['cycle' => '8.5', 'latest' => '8.5.10', 'support' => '2027-12-31', 'eol' => '2029-12-31'],
            ['cycle' => '8.4', 'latest' => '8.4.25', 'support' => '2026-12-31', 'eol' => '2028-12-31'],
        ]];
        $phpHtml = (new SentinelReport($phpAudit))->render();
        $phpRow  = substr($phpHtml, strpos($phpHtml, '>PHP<'), 1500);
        $this->assertStringContainsString('8.4.20 → 8.4.25', $phpRow);
        $this->assertStringNotContainsString('8.4.20 → 8.5.10', $phpRow);
        $this->assertStringContainsString('PHP 8.5.10 is also available', $phpRow);
        $this->assertLessThan(strpos($phpRow, 'Update available'), strpos($phpRow, 'Major version behind'));

        // On the latest patch of a security-only branch: no update pill.
        $phpAudit['php'] = ['version' => '8.3.33', 'latest' => '8.5.10', 'status' => 'security', 'label' => 'Security Fixes Only', 'branches' => [
            ['cycle' => '8.5', 'latest' => '8.5.10'], ['cycle' => '8.3', 'latest' => '8.3.33'],
        ]];
        $phpRow = substr($html = (new SentinelReport($phpAudit))->render(), strpos($html, '>PHP<'), 1500);
        $this->assertStringContainsString('Security only', $phpRow);
        $this->assertStringNotContainsString('Update available', $phpRow);

        // Platform versions sit beside the title, not in front of the pills.
        $this->assertMatchesRegularExpression('/>Statamic<span[^>]*>6\.0\.0 → 6\.1\.0<\/span><\/div>/u', $rendered['status']);
        $this->assertStringContainsString('1 hour 30 minutes', $rendered['notification']);
    }

    /**
     * Cached audits and freeze records written by older versions lack newer
     * keys; the emails must still render.
     */
    public function test_emails_render_with_minimal_older_data(): void
    {
        $audit = $this->audit();
        unset($audit['license'], $audit['composer']['outdated'], $audit['npm']['by_package'], $audit['statamic']['security_source']);

        $this->assertNotSame('', (new SentinelReport($audit))->render());
        $this->assertNotSame('', (new FreezeNotificationMail(['freeze_at' => Carbon::now()->toIso8601String()]))->render());
        $this->assertNotSame('', (new FreezeCompletionMail(['freeze_at' => Carbon::now()->toIso8601String()]))->render());
        $this->assertNotSame('', (new SentinelMaintenanceReport(MaintenanceReportBuilder::build([], [])))->render());
    }

    public function test_every_controller_endpoint_refuses_non_supers(): void
    {
        $this->actingAs(new ViewTestUser(false));

        foreach ([SentinelController::class, FreezeController::class] as $class) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->class !== $class || $method->isConstructor()) {
                    continue;
                }

                try {
                    $this->app->call([$this->app->make($class), $method->name], ['request' => Request::create('/'), 'id' => 'abcdefghijklmnop']);
                    $this->fail("{$class}::{$method->name} did not refuse a non-super.");
                } catch (HttpException $e) {
                    $this->assertSame(403, $e->getStatusCode(), "{$class}::{$method->name}");
                }
            }
        }
    }

    public function test_freeze_schedule_rejects_array_input_without_a_500(): void
    {
        $this->actingAs(new ViewTestUser(true));

        $response = $this->app->call([$this->app->make(FreezeController::class), 'schedule'], [
            'request' => Request::create('/', 'POST', ['email' => ['a@example.test'], 'notify_at' => ['x'], 'freeze_at' => ['y']]),
        ]);

        $this->assertSame(422, $response->getStatusCode());
    }

    protected function audit(): array
    {
        $eco = [
            'status'         => 'vulnerable',
            'total_packages' => 10,
            'total_vulns'    => 1,
            'counts'         => ['CRITICAL' => 0, 'HIGH' => 1, 'MEDIUM' => 0, 'LOW' => 0, 'UNKNOWN' => 0],
            'severities'     => ['HIGH' => ['count' => 1, 'packages' => ['a/a'], 'vulns' => [['id' => 'GHSA-1', 'cve' => 'CVE-2026-1', 'severity' => 'HIGH', 'package' => 'a/a', 'summary' => 'Bad', 'fix_available' => true, 'url' => 'https://osv.dev/vulnerability/GHSA-1']]]],
            'by_package'     => [['name' => 'a/a', 'highest' => 'HIGH', 'count' => 1, 'vulns' => []]],
            'outdated'       => ['total' => 1, 'security_updates_total' => 1, 'packages' => [['name' => 'a/a', 'current' => '1.0.0', 'latest' => '2.0.0', 'security_update' => true, 'security_source' => 'osv']]],
        ];

        return [
            'statamic'   => ['current' => '6.0.0', 'latest' => '6.1.0', 'is_latest' => false, 'status' => 'outdated', 'security_update_available' => true, 'security_source' => 'osv', 'releases_behind' => 1],
            'laravel'    => ['version' => '13.0.0', 'latest' => '13.0.0', 'is_latest' => true, 'status' => 'active', 'label' => 'Active Support', 'security_update_available' => false],
            'php'        => ['version' => '8.1.0', 'latest' => '8.5.0', 'is_latest' => false, 'status' => 'eol', 'label' => 'End of Life'],
            'license'    => ['supported' => true, 'status' => 'renewal'],
            'composer'   => $eco,
            'npm'        => $eco,
            'audited_at' => '17 Sep 2026, 09:00',
        ];
    }
}
