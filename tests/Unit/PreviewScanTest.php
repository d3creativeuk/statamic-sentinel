<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Http\Controllers\SentinelController;
use D3Creative\Sentinel\Services\AuditService;
use D3Creative\Sentinel\Tests\Support\ActsAsStatamicUser;
use D3Creative\Sentinel\Tests\Support\RegistersViews;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * The preview endpoints are GETs, so a link on another site can open them in
 * a signed-in super's browser. With nothing cached they used to run a full
 * scan, outside Scan now's token, cooldown and lock.
 */
class PreviewScanTest extends TestCase
{
    use ActsAsStatamicUser;
    use RegistersViews;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Storage::fake('local');
        Http::fake();
        $this->registerViews();
        $this->actingAsStatamicUser(true);
    }

    protected function tearDown(): void
    {
        @unlink(base_path('composer.lock'));

        parent::tearDown();
    }

    public function test_previews_do_not_scan_when_nothing_is_cached(): void
    {
        $status = (new SentinelController)->previewReport(new Request)->getContent();
        $update = (new SentinelController)->previewUpdateReport(new Request)->getContent();

        $this->assertStringContainsString('No scan yet', $status);
        $this->assertStringContainsString('No earlier snapshot', $update);
        Http::assertNothingSent();
        $this->assertNull(Cache::get(AuditService::CACHE_KEY));
    }

    /**
     * Stored sent-email snapshots are served as-is from the CP's origin, so
     * every preview is sandboxed: no script runs even if a template slips.
     */
    public function test_previews_are_served_sandboxed(): void
    {
        $csp = (new SentinelController)->previewReport(new Request)->headers->get('Content-Security-Policy');

        $this->assertStringStartsWith('sandbox;', $csp);
        $this->assertStringContainsString("default-src 'none'", $csp);
    }

    public function test_the_status_preview_renders_a_cached_audit(): void
    {
        // The cached audit is reconciled against the live Statamic version,
        // which Statamic reads from composer.lock.
        file_put_contents(base_path('composer.lock'), json_encode(['packages' => [['name' => 'statamic/cms', 'version' => 'v6.0.0']], 'packages-dev' => []]));

        Cache::forever(AuditService::CACHE_KEY, [
            'audited_at' => '2026-10-08T09:00:00+00:00',
            'statamic'   => ['current' => '6.0.0', 'latest' => '6.0.0'],
            'laravel'    => ['version' => '12.0.0'],
            'php'        => ['version' => '8.4.0'],
            'composer'   => ['status' => 'ok', 'total_vulns' => 0, 'outdated' => ['total' => 0]],
            'npm'        => ['status' => 'unavailable', 'total_vulns' => 0, 'outdated' => ['total' => 0]],
        ]);

        $html = (new SentinelController)->previewReport(new Request)->getContent();

        $this->assertStringNotContainsString('No scan yet', $html);
        Http::assertNothingSent();
    }
}
