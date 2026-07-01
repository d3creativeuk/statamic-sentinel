<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Mail\FreezeCompletionMail;
use D3Creative\Sentinel\Mail\FreezeNotificationMail;
use D3Creative\Sentinel\Mail\SentinelUpdateReport;
use D3Creative\Sentinel\Support\ReportHosts;
use D3Creative\Sentinel\Tests\TestCase;
use Statamic\Sites\Sites;

/**
 * Covers the multisite report labelling: a report header + email subject should
 * list every Statamic site host (comma-separated), falling back to the single
 * config('app.url') host when the Sites system isn't available (CLI without a
 * booted Statamic, older versions, or any error).
 */
class ReportHostsTest extends TestCase
{
    /**
     * Bind a stand-in Sites repository under the Site facade's accessor so
     * Statamic\Facades\Site::all() resolves it without booting Statamic. Each
     * site only needs the absoluteUrl() ReportHosts reads.
     *
     * @param array<int, string> $urls
     */
    private function bindSites(array $urls): void
    {
        $sites = collect($urls)->map(fn ($url) => new class($url)
        {
            public function __construct(private string $url)
            {
            }

            public function absoluteUrl(): string
            {
                return $this->url;
            }
        });

        $this->app->instance(Sites::class, new class($sites)
        {
            public function __construct(private $sites)
            {
            }

            public function all()
            {
                return $this->sites;
            }
        });
    }

    public function test_all_lists_every_site_host_deduped(): void
    {
        $this->bindSites([
            'https://a.test',
            'https://b.test/fr',
            'https://a.test/de', // same host as the first - deduped
        ]);

        $this->assertSame(['a.test', 'b.test'], ReportHosts::all());
        $this->assertSame('a.test, b.test', ReportHosts::label());
    }

    public function test_all_falls_back_to_app_url_when_sites_unavailable(): void
    {
        config(['app.url' => 'https://primary.test']);

        // Sites is not bound: without a booted Statamic the real repo can't
        // resolve, which ReportHosts swallows and falls back to the app URL host.
        $this->assertSame(['primary.test'], ReportHosts::all());
    }

    public function test_all_filters_sites_without_a_host_then_falls_back(): void
    {
        config(['app.url' => 'https://primary.test']);

        $this->bindSites(['/', '']); // relative + empty - no host on either

        $this->assertSame(['primary.test'], ReportHosts::all());
    }

    public function test_update_report_subject_lists_all_hosts(): void
    {
        $this->bindSites(['https://a.test', 'https://b.test']);

        $mailable = (new SentinelUpdateReport([]))->build();

        $this->assertSame('a.test, b.test updates', $mailable->subject);
    }

    public function test_freeze_completion_subject_lists_all_hosts(): void
    {
        $this->bindSites(['https://a.test', 'https://b.test']);

        $mailable = (new FreezeCompletionMail(['completed_at' => '2026-07-01T14:41:00Z']))->build();

        $this->assertSame('a.test, b.test update complete: safe to log back in', $mailable->subject);
    }

    public function test_freeze_notification_subject_lists_all_hosts(): void
    {
        $this->bindSites(['https://a.test', 'https://b.test']);

        $mailable = (new FreezeNotificationMail(['freeze_at' => '2026-07-01T13:41:00Z']))->build();

        $this->assertStringStartsWith('a.test, b.test update scheduled for', $mailable->subject);
    }
}
