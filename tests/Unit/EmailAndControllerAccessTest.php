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
use D3Creative\Sentinel\Tests\Support\ActsAsStatamicUser;
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
    use RegistersViews, ActsAsStatamicUser;

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
        // With security issues, the pill is the whole row summary.
        $this->assertStringContainsString('1 security issue</span>', $rendered['status']);
        $this->assertStringNotContainsString('across 1 package', $rendered['status']);
        $this->assertStringNotContainsString('1 update available', $rendered['status']);

        $this->assertStringContainsString("Did you know?</strong> Your website isn't a single piece of software.", $rendered['status']);

        // The banner reads like an email about the Statamic install.
        $this->assertStringContainsString("Hi, your Statamic installation is running version 6.0.0. The latest version is 6.1.0. That&#039;s 1 version behind.", $rendered['status']);

        $licensed = $this->audit();
        $licensed['license']['status'] = 'ok';
        $licensed['statamic']['releases_behind'] = 34;
        $licensedHtml = (new SentinelReport($licensed))->render();
        $this->assertStringContainsString('That&#039;s 34 versions behind.</div>', $licensedHtml);
        $this->assertStringNotContainsString('active license', $licensedHtml);

        // Out of date with no behind count: the standard headline, not a shorter message.
        $noCount = $this->audit();
        unset($noCount['statamic']['releases_behind']);
        $noCountHtml = (new SentinelReport($noCount))->render();
        $this->assertStringNotContainsString('Hi, your Statamic', $noCountHtml);
        $this->assertStringContainsString('Your Statamic website needs attention', $noCountHtml);

        $current = $this->audit();
        $current['statamic'] = ['current' => '6.1.0', 'latest' => '6.1.0', 'is_latest' => true, 'status' => 'ok'];
        $this->assertStringContainsString('Hi, your Statamic installation is running the latest version, 6.1.0.', (new SentinelReport($current))->render());

        // The greeting covers Statamic only, so something urgent elsewhere
        // (here vulnerabilities and an end-of-life PHP) follows it rather
        // than hiding behind "running the latest version".
        $currentHtml = (new SentinelReport($current))->render();
        $this->assertMatchesRegularExpression('#latest version, 6\.1\.0\.</div>\s*<div[^>]*>Your Statamic website needs attention\.</div>\s*<div[^>]*>Security or platform issues were found\.</div>#', $currentHtml);

        // With nothing urgent the greeting stands alone.
        $healthy = $current;
        $healthy['php'] = ['version' => '8.5.0', 'latest' => '8.5.0', 'is_latest' => true, 'status' => 'active', 'label' => 'Active Support'];
        $healthy['license'] = ['supported' => true, 'status' => 'ok'];
        foreach (['composer', 'npm'] as $eco) {
            $healthy[$eco] = ['status' => 'ok', 'total_vulns' => 0, 'counts' => [], 'severities' => [], 'by_package' => [], 'outdated' => ['total' => 0, 'packages' => []]];
        }
        $healthyHtml = (new SentinelReport($healthy))->render();
        $this->assertStringContainsString('running the latest version, 6.1.0.', $healthyHtml);
        $this->assertStringNotContainsString('good health', $healthyHtml);

        // A patch release behind is said to be one, and not urgent...
        $patchBehind = $healthy;
        $patchBehind['statamic'] = ['current' => '6.30.0', 'latest' => '6.30.1', 'is_latest' => false, 'status' => 'outdated', 'releases_behind' => 1];
        $this->assertStringContainsString('The latest version is 6.30.1. That&#039;s 1 patch version behind, not urgent.', (new SentinelReport($patchBehind))->render());

        // ...unless it's a security fix.
        $patchBehind['statamic']['security_update_available'] = true;
        $securityPatchHtml = (new SentinelReport($patchBehind))->render();
        $this->assertStringContainsString('That&#039;s 1 patch version behind.', $securityPatchHtml);
        $this->assertStringNotContainsString('not urgent', $securityPatchHtml);

        // A minor gap reads as plain versions.
        $patchBehind['statamic'] = ['current' => '6.30.0', 'latest' => '6.35.1', 'is_latest' => false, 'status' => 'outdated', 'releases_behind' => 5];
        $this->assertStringContainsString('That&#039;s 5 versions behind.', (new SentinelReport($patchBehind))->render());

        // An unknown latest version (registry unreachable) isn't "the latest".
        $unknown = $this->audit();
        $unknown['statamic'] = ['current' => '6.1.0', 'latest' => null, 'status' => 'unknown'];
        $this->assertStringNotContainsString('running the latest version', (new SentinelReport($unknown))->render());

        // A failed update check says so instead of "Up to date".
        $failed = $this->audit();
        foreach (['composer', 'npm'] as $eco) {
            $failed[$eco] = array_merge($failed[$eco], ['status' => 'ok', 'total_vulns' => 0, 'counts' => [], 'severities' => [], 'by_package' => [], 'outdated' => ['total' => 0, 'packages' => [], 'error' => true]]);
        }
        $failedHtml = (new SentinelReport($failed))->render();
        $this->assertSame(2, substr_count($failedHtml, 'Update check failed'));

        // A security flag and a major gap are separate pills: the security
        // pill first, then the tier with "update available" beside it.
        $majorAudit = $this->audit();
        $majorAudit['statamic'] = ['current' => '5.73.2', 'latest' => '6.33.0', 'is_latest' => false, 'status' => 'outdated', 'security_update_available' => true, 'security_source' => 'osv'];
        $majorHtml  = (new SentinelReport($majorAudit))->render();
        $this->assertMatchesRegularExpression('#>Security update</span>\s*<span[^>]*background:\#dc2626;">Major</span>\s*<span[^>]*>update available</span>#', $majorHtml);

        // PHP compares against the newest release on any branch, and a new
        // X.Y branch counts as major.
        $phpAudit = $this->audit();
        $phpAudit['php'] = ['version' => '8.4.20', 'latest' => '8.5.10', 'status' => 'active', 'label' => 'Active Support'];
        $phpRow = substr($html = (new SentinelReport($phpAudit))->render(), strpos($html, '>PHP<'), 1500);
        $this->assertStringContainsString('8.4.20 → 8.5.10', $phpRow);
        $this->assertMatchesRegularExpression('#>Major</span>\s*<span[^>]*>update available</span>#', $phpRow);
        $this->assertStringNotContainsString('is also available', $phpRow);

        // A patch is labelled as one, in the muted blue.
        $patch = $this->audit();
        $patch['php'] = ['version' => '8.5.10', 'latest' => '8.5.11', 'status' => 'active', 'label' => 'Active Support'];
        $patchRow = substr($html = (new SentinelReport($patch))->render(), strpos($html, '>PHP<'), 1500);
        $this->assertMatchesRegularExpression('#background:\#4f73b8;">Patch</span>\s*<span[^>]*>update available</span>#', $patchRow);

        // And a minor, in the stronger blue.
        $minor = $this->audit();
        $minor['laravel'] = ['version' => '13.30.0', 'latest' => '13.35.0', 'status' => 'active', 'label' => 'Active Support'];
        $minorRow = substr($html = (new SentinelReport($minor))->render(), strpos($html, '>Laravel<'), 1500);
        $this->assertMatchesRegularExpression('#background:\#2563eb;">Minor</span>\s*<span[^>]*>update available</span>#', $minorRow);

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
        $this->actingAsStatamicUser(false);

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

    /**
     * On database-user sites auth()->user() is the host's Eloquent model. A
     * query scope named isSuper made `$model->isSuper()` return a truthy
     * Builder, so a truthy guard let any CP user through.
     */
    public function test_a_host_model_scope_named_is_super_does_not_open_the_endpoints(): void
    {
        $this->actingAsStatamicUser(false, 'editor@example.test', \D3Creative\Sentinel\Tests\Support\ScopedHostEloquentUser::class);

        foreach ([SentinelController::class, FreezeController::class] as $class) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->class !== $class || $method->isConstructor()) {
                    continue;
                }

                try {
                    $this->app->call([$this->app->make($class), $method->name], ['request' => Request::create('/'), 'id' => 'abcdefghijklmnop']);
                    $this->fail("{$class}::{$method->name} let a non-super through.");
                } catch (HttpException $e) {
                    $this->assertSame(403, $e->getStatusCode(), "{$class}::{$method->name}");
                }
            }
        }
    }

    public function test_freeze_schedule_rejects_array_input_without_a_500(): void
    {
        $this->actingAsStatamicUser(true);

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
