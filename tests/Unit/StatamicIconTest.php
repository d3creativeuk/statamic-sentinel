<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Mail\SentinelReport;
use D3Creative\Sentinel\Support\StatamicIcon;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Mail\Message;
use Symfony\Component\Mime\Email;

/**
 * The Statamic row carries Statamic's favicon from the installed package:
 * embedded when sent (Gmail drops data: images), a data: URI in previews.
 */
class StatamicIconTest extends TestCase
{
    public function test_a_sent_email_embeds_it_and_a_preview_inlines_it(): void
    {
        $this->assertStringStartsWith('data:image/png;base64,', StatamicIcon::src());

        $email = new Email;
        $cid   = StatamicIcon::src(new Message($email));

        $this->assertStringStartsWith('cid:', $cid);
        $this->assertCount(1, $email->getAttachments());
    }

    public function test_the_status_report_shows_it_on_the_statamic_row_only(): void
    {
        $this->app['view']->addNamespace('statamic-sentinel', __DIR__ . '/../../resources/views');

        $html = (new SentinelReport([
            'statamic' => ['current' => '6.0.0', 'latest' => '6.0.0', 'status' => 'ok'],
            'laravel'  => ['version' => '13.0.0', 'latest' => '13.0.0', 'status' => 'active'],
            'php'      => ['version' => '8.4.0', 'latest' => '8.4.0', 'status' => 'active'],
            'composer' => ['status' => 'ok', 'total_vulns' => 0, 'outdated' => ['total' => 0]],
            'npm'      => ['status' => 'unavailable'],
            'audited_at' => '9 Oct 2026, 09:00',
        ]))->render();

        $this->assertSame(1, substr_count($html, 'src="data:image/png;base64,'));
        $this->assertMatchesRegularExpression('#src="data:image/png;base64,[^"]+"[^>]*>Statamic#', $html);
    }
}
