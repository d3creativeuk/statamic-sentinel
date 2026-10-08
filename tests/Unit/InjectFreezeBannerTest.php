<?php

namespace D3Creative\Sentinel\Tests\Unit;

use Carbon\Carbon;
use D3Creative\Sentinel\Http\Middleware\InjectFreezeBanner;
use D3Creative\Sentinel\Services\ContentFreezeService;
use D3Creative\Sentinel\Support\CpAccess;
use D3Creative\Sentinel\Tests\Support\RegistersViews;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Where the freeze banner goes, and where it must not. Statamic 6 renders the
 * whole Inertia page into one data-page attribute on the #statamic div; once
 * that passed about 1 MB, the old shell regex ran out of PCRE backtracking
 * and the banner was silently dropped.
 */
class InjectFreezeBannerTest extends TestCase
{
    use RegistersViews;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->registerViews();
        config(['statamic.cp.enabled' => true]);

        $this->app->instance(CpAccess::class, new class extends CpAccess {
            public function allows(): bool
            {
                return true;
            }
        });
    }

    public function test_a_statamic_6_page_with_a_large_data_page_gets_the_overlay(): void
    {
        $this->activeFreeze();

        $html = $this->inject($this->statamic6Shell(2_000_000));

        $this->assertStringContainsString('id="d3-sentinel-freeze-overlay"', $html);
    }

    public function test_a_small_statamic_6_page_gets_the_overlay(): void
    {
        $this->activeFreeze();

        $this->assertStringContainsString('id="d3-sentinel-freeze-overlay"', $this->inject($this->statamic6Shell(1_000)));
    }

    public function test_a_statamic_5_page_gets_the_banner_inside_the_workspace(): void
    {
        $this->activeFreeze();

        $html = $this->inject('<!DOCTYPE html><html><head></head><body><div id="statamic"><div class="global-header"></div><div id="main"><div class="workspace"><p>Page</p></div></div></div></body></html>');

        $this->assertStringNotContainsString('d3-sentinel-freeze-overlay', $html);

        $banner    = strpos($html, 'sentinel_freeze_modal_seen_freeze_test');
        $workspace = strpos($html, '<div class="workspace">');
        $this->assertNotFalse($banner);
        $this->assertGreaterThan($workspace, $banner);
        $this->assertLessThan(strpos($html, '<p>Page</p>'), $banner);
    }

    public function test_html_without_the_cp_shell_is_left_alone(): void
    {
        $this->activeFreeze();

        $email   = '<!DOCTYPE html><html><head></head><body><p>Plan &quot; id=&quot;statamic&quot;</p></body></html>';
        $escaped = '<!DOCTYPE html><html><head></head><body><div data-page="{&quot;id&quot;:&quot;statamic&quot;}"></div></body></html>';

        $this->assertSame($email, $this->inject($email));
        $this->assertSame($escaped, $this->inject($escaped));
    }

    public function test_a_statamic_5_page_is_left_alone_without_a_freeze(): void
    {
        $shell = '<!DOCTYPE html><html><head></head><body><div id="statamic"><div id="main"><div class="workspace"><p>Page</p></div></div></div></body></html>';

        $this->assertSame($shell, $this->inject($shell));
    }

    /**
     * Statamic 6 never reloads the page between navigations, so the overlay
     * and its script go in on every full load, empty when there's no freeze,
     * ready to show a banner that appears later in the session.
     */
    public function test_a_statamic_6_page_gets_an_empty_overlay_without_a_freeze(): void
    {
        $html = $this->inject($this->statamic6Shell(1_000));

        $this->assertMatchesRegularExpression('/<div id="d3-sentinel-freeze-overlay" data-key="none" data-transition-at="" data-endpoint="http[^"]+"[^>]*><\/div>/', $html);
        $this->assertStringContainsString("addEventListener('inertia:navigate'", $html);
    }

    public function test_the_overlay_carries_the_banner_key_and_start_time(): void
    {
        $freezeAt = Carbon::now()->addHour()->utc()->toIso8601String();

        Storage::disk('local')->put(ContentFreezeService::CURRENT_PATH, json_encode([
            'id'         => 'freeze_soon',
            'status'     => ContentFreezeService::STATUS_NOTIFIED,
            'notify_at'  => Carbon::now()->subHour()->toIso8601String(),
            'freeze_at'  => $freezeAt,
            'recipients' => [],
        ]));

        $html = $this->inject($this->statamic6Shell(1_000));

        $this->assertStringContainsString('data-key="upcoming:freeze_soon"', $html);
        $this->assertStringContainsString('data-transition-at="' . e($freezeAt) . '"', $html);
    }

    /**
     * Inertia responses carry the key, so the script can spot a change after
     * any navigation without a request of its own.
     */
    public function test_the_banner_key_is_shared_with_inertia_pages(): void
    {
        if (! class_exists(\Inertia\Inertia::class)) {
            $this->markTestSkipped('Inertia ships with Statamic 6 only.');
        }

        $this->activeFreeze();

        $this->inject($this->statamic6Shell(1_000));

        $shared = \Inertia\Inertia::getShared('sentinelFreeze');
        $this->assertSame('active:freeze_test', is_callable($shared) ? $shared() : $shared);
    }

    protected function activeFreeze(): void
    {
        Storage::disk('local')->put(ContentFreezeService::CURRENT_PATH, json_encode([
            'id'           => 'freeze_test',
            'status'       => ContentFreezeService::STATUS_ACTIVE,
            'notify_at'    => Carbon::now()->subHours(2)->toIso8601String(),
            'freeze_at'    => Carbon::now()->subHour()->toIso8601String(),
            'activated_at' => Carbon::now()->subHour()->toIso8601String(),
            'recipients'   => [],
        ]));
    }

    /**
     * Statamic 6's layout: a multi-line #statamic div whose data-page holds
     * the HTML-escaped page JSON, with no raw `>` inside it.
     */
    protected function statamic6Shell(int $dataPageBytes): string
    {
        $page = e(json_encode(['component' => 'utilities/Show', 'props' => ['html' => str_repeat('<p>x</p>', intdiv($dataPageBytes, 8))]]));

        return "<!DOCTYPE html><html><head></head><body>\n<div\n    id=\"statamic\"\n    data-page=\"{$page}\"\n></div>\n</body></html>";
    }

    protected function inject(string $html): string
    {
        $response = new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);

        return (new InjectFreezeBanner)
            ->handle(Request::create('/cp/utilities/sentinel'), fn () => $response)
            ->getContent();
    }
}
