<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Services\ContentFreezeService;
use D3Creative\Sentinel\Tests\Support\RegistersViews;
use D3Creative\Sentinel\Tests\Support\ViewTestUser;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

/**
 * Renders the utility and widget with realistic audit payloads, so a Blade
 * or PHP error in a view (an unguarded key, a typo in an Alpine wrapper)
 * fails here instead of on a live Control Panel. Statamic's layout is
 * stubbed and the addon's named routes are registered as placeholders.
 */
class ViewRenderTest extends TestCase
{
    use RegistersViews;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->registerViews();
    }

    public function test_utility_renders_for_a_super_with_a_full_payload(): void
    {
        $this->actingAs(new ViewTestUser(true));

        $html = $this->renderUtility($this->audit());

        $this->assertStringContainsString('v-pre', $html);
        $this->assertStringContainsString('8.0.30 → 8.5.7 (EOL)', $html);
        $this->assertStringContainsString('3 security issues', $html);
        $this->assertStringContainsString('Vendor security release', $html);
    }

    public function test_tabs_panels_and_dialogs_are_wired_for_assistive_tech(): void
    {
        $this->actingAs(new ViewTestUser(true));

        $html = $this->renderUtility($this->audit());

        foreach (['current', 'history', 'status-report', 'update-report', 'maintenance-report', 'users', 'content-freeze'] as $key) {
            $this->assertStringContainsString('id="sentinel-tab-' . $key . '"', $html);
            $this->assertStringContainsString('aria-controls="sentinel-panel-' . $key . '"', $html);
            $this->assertMatchesRegularExpression('/id="sentinel-panel-' . $key . '"\s+aria-labelledby="sentinel-tab-' . $key . '"/', $html);
        }

        $this->assertStringContainsString('aria-labelledby="sentinel-confirm-title"', $html);
        $this->assertSame(4, substr_count($html, 'aria-label="Recipient email addresses"')); // three report forms + Notify
        $this->assertStringNotContainsString('outline:none', $html);
    }

    /**
     * Statamic compiles this HTML as a Vue template (3.3-5 via #statamic, 6 via
     * dynamic-html-renderer). A raw `"` inside a double-quoted Alpine attribute
     * ends it early; the rest of the expression becomes junk attributes, the
     * template fails to compile and the utility renders empty tabs that can't
     * be clicked. That happened with `valid: @json([...])`: json_encode's
     * JSON_HEX_QUOT never escapes the quotes that delimit strings.
     */
    public function test_alpine_attributes_are_not_cut_short_by_quotes(): void
    {
        $this->actingAs(new ViewTestUser(true));

        $pages = [
            'utility' => $this->renderUtility($this->audit()),
            'widget'  => (string) view('statamic-sentinel::widgets.sentinel', ['audit' => $this->audit()]),
        ];

        foreach ($pages as $page => $html) {
            preg_match_all('/\s(x-[\w:.\-]+)="([^"]*)"/', $html, $attributes, PREG_SET_ORDER);

            $this->assertNotEmpty($attributes, $page);

            foreach ($attributes as [, $name, $value]) {
                foreach (['{' => '}', '[' => ']', '(' => ')'] as $open => $close) {
                    $this->assertSame(
                        substr_count($value, $open),
                        substr_count($value, $close),
                        "{$page}: {$name} looks cut short: " . substr(preg_replace('/\s+/', ' ', $value), 0, 120)
                    );
                }
            }
        }
    }

    public function test_utility_renders_for_a_non_super(): void
    {
        $this->actingAs(new ViewTestUser(false));

        $html = $this->renderUtility($this->audit());

        $this->assertStringContainsString('Refresh', $html);
        $this->assertStringNotContainsString('Email plan summary', $html);
    }

    public function test_utility_renders_an_audit_cached_before_newer_keys_existed(): void
    {
        $this->actingAs(new ViewTestUser(true));

        $audit = $this->audit();
        unset($audit['license'], $audit['composer']['by_package'], $audit['composer']['dependency_parents'], $audit['statamic']['releases_behind']);
        $audit['composer']['outdated']['packages'] = array_map(
            fn ($p) => array_diff_key($p, array_flip(['security_fixed_in', 'blocked', 'release_age_unknown'])),
            $audit['composer']['outdated']['packages']
        );

        $this->assertStringContainsString('Sentinel', $this->renderUtility($audit));
    }

    public function test_utility_renders_the_empty_state(): void
    {
        $this->actingAs(new ViewTestUser(true));

        $this->assertStringContainsString('No scan yet', $this->renderUtility(null));
    }

    public function test_widget_renders(): void
    {
        $html = (string) view('statamic-sentinel::widgets.sentinel', ['audit' => $this->audit()]);

        $this->assertStringContainsString('8.0.30 → 8.5.7 (EOL)', $html);
        $this->assertStringContainsString('>3</span>', $html);
    }

    protected function renderUtility(?array $audit): string
    {
        $freeze = new ContentFreezeService;

        return (string) view('statamic-sentinel::utilities.sentinel', [
            'audit'                       => $audit,
            'history'                     => [],
            'schedule'                    => ['status_report' => ['enabled' => false, 'frequency' => 'daily', 'day_of_week' => 1, 'day_of_month' => 1, 'time' => '09:00', 'recipients' => []]],
            'sent_status'                 => [['id' => 'abcdefghijklmnop', 'recorded_at' => '2026-09-01T09:00:00Z', 'recipients' => ['a@b.test'], 'trigger' => 'manual', 'outcome' => 'sent']],
            'sent_update'                 => [['recorded_at' => '2026-09-01T09:00:00Z', 'recipients' => ['a@b.test']]],
            'sent_maintenance'            => [],
            'last_status_recipients'      => [],
            'last_update_recipients'      => [],
            'last_maintenance_recipients' => [],
            'maintenance_plan'            => ['plan_name' => '', 'start_date' => '', 'expiry_date' => ''],
            'users'                       => [['id' => '1', 'name' => '{{ 7*7 }}', 'email' => 'x@y.test', 'is_super' => true, 'last_login' => null, 'last_active' => null]],
            'online_window'               => 5,
            'freeze'                      => $freeze,
            'freeze_current'              => null,
            'freeze_history'              => [],
        ]);
    }

    protected function audit(): array
    {
        $ecosystem = fn (array $extra = []) => array_replace_recursive([
            'status'         => 'vulnerable',
            'total_packages' => 120,
            'total_vulns'    => 2,
            'counts'         => ['CRITICAL' => 0, 'HIGH' => 1, 'MEDIUM' => 1, 'LOW' => 0, 'UNKNOWN' => 0],
            'severities'     => [],
            'by_package'     => [[
                'name'    => 'acme/pkg',
                'highest' => 'HIGH',
                'count'   => 2,
                'vulns'   => [
                    ['id' => 'GHSA-aaaa', 'cve' => 'CVE-2026-0001', 'severity' => 'HIGH', 'url' => 'https://osv.dev/vulnerability/GHSA-aaaa'],
                    ['id' => 'GHSA-bbbb', 'cve' => null, 'severity' => 'MEDIUM', 'url' => 'https://osv.dev/vulnerability/GHSA-bbbb'],
                ],
            ]],
            'dependency_parents' => [],
            'outdated'       => [
                'total'                         => 2,
                'security_updates_total'        => 1,
                'vendor_security_updates_total' => 1,
                'packages'                      => [
                    ['name' => 'acme/pkg', 'current' => '1.0.0', 'latest' => '1.2.0', 'security_update' => true, 'security_source' => 'osv', 'security_fixed_in' => ['osv' => '1.2.0', 'vendor' => null]],
                    ['name' => 'acme/seo', 'current' => '2.0.0', 'latest' => '2.1.0', 'security_update' => true, 'security_source' => 'vendor', 'security_fixed_in' => ['osv' => null, 'vendor' => '2.1.0']],
                ],
            ],
            'installed'      => [],
        ], $extra);

        return [
            'statamic'   => ['current' => '6.0.0', 'latest' => '6.5.0', 'is_latest' => false, 'releases_behind' => 5, 'status' => 'outdated', 'security_update_available' => false, 'security_source' => null],
            'laravel'    => ['version' => '13.0.0', 'latest' => '13.5.0', 'is_latest' => false, 'releases_behind' => 3, 'status' => 'active', 'label' => 'Active Support', 'security_update_available' => false],
            'php'        => ['version' => '8.0.30', 'latest' => '8.5.7', 'is_latest' => false, 'releases_behind' => 20, 'status' => 'eol', 'label' => 'End of Life'],
            'license'    => ['supported' => true, 'status' => 'ok'],
            'composer'   => $ecosystem(),
            'npm'        => $ecosystem([
                'outdated' => [
                    'vendor_security_updates_total' => 0,
                    'packages' => [
                        ['name' => 'vite', 'current' => '8.2.2', 'latest' => '8.3.0', 'blocked' => true, 'blocked_until' => '2026-09-17', 'available_in_days' => 1],
                        ['name' => 'tailwindcss', 'current' => '4.3.2', 'latest' => '4.3.3', 'release_age_unknown' => true],
                    ],
                ],
            ]),
            'audited_at' => '16 Sep 2026, 09:00',
        ];
    }
}
