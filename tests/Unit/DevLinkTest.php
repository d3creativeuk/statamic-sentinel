<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Support\DevLink;
use D3Creative\Sentinel\Tests\Support\RegistersViews;
use D3Creative\Sentinel\Tests\TestCase;

class DevLinkTest extends TestCase
{
    use RegistersViews;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://client-site.example']);
    }

    public function test_d3_links_are_tagged_with_where_they_appear_and_the_site(): void
    {
        $url = DevLink::tag('https://d3creative.uk/services/statamic-maintenance', 'email', 'status-report');

        $this->assertSame([
            'utm_source'   => 'sentinel',
            'utm_medium'   => 'email',
            'utm_campaign' => 'status-report',
            'utm_content'  => 'client-site.example',
        ], $this->query($url));
        $this->assertStringStartsWith('https://d3creative.uk/services/statamic-maintenance?', $url);
    }

    public function test_white_label_links_are_tagged_the_same_way(): void
    {
        $url = DevLink::tag('https://acme.test/care', 'cp', 'widget');

        $this->assertStringStartsWith('https://acme.test/care?', $url);
        $this->assertSame(['utm_source' => 'sentinel', 'utm_medium' => 'cp', 'utm_campaign' => 'widget', 'utm_content' => 'client-site.example'], $this->query($url));
    }

    public function test_anything_but_a_web_link_is_left_alone(): void
    {
        foreach (['mailto:help@acme.test', '/relative/path', ''] as $url) {
            $this->assertSame($url, DevLink::tag($url, 'cp', 'widget'));
        }

        $this->assertNull(DevLink::tag(null, 'cp', 'widget'));
    }

    public function test_parameters_already_on_the_url_win_and_fragments_survive(): void
    {
        $url = DevLink::tag('https://www.d3creative.uk/care?utm_campaign=autumn#plans', 'cp', 'utility');

        $this->assertStringEndsWith('#plans', $url);
        $this->assertSame('autumn', $this->query($url)['utm_campaign']);
        $this->assertSame('cp', $this->query($url)['utm_medium']);
    }

    public function test_the_widget_footer_link_is_tagged(): void
    {
        $this->registerViews();

        $html = (string) view('statamic-sentinel::widgets.sentinel', [
            'audit'           => null,
            'sentinelDevName' => 'D3 Creative',
            'sentinelDevUrl'  => 'https://d3creative.uk/services/statamic-maintenance',
        ]);

        $this->assertStringContainsString(
            e('https://d3creative.uk/services/statamic-maintenance?utm_source=sentinel&utm_medium=cp&utm_campaign=widget&utm_content=client-site.example'),
            $html
        );
    }

    protected function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }
}
