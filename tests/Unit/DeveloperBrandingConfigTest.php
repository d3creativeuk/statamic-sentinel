<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Tests\TestCase;

/**
 * An agency that set only SENTINEL_DEV_NAME got its name in the footer, but
 * the link and the "Need help" button still went to D3 Creative.
 */
class DeveloperBrandingConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['SENTINEL_DEV_NAME', 'SENTINEL_DEV_URL', 'SENTINEL_DEV_EMAIL'] as $var) {
            putenv($var);
            unset($_ENV[$var], $_SERVER[$var]);
        }

        parent::tearDown();
    }

    public function test_the_default_is_d3_with_its_link_and_email(): void
    {
        $developer = $this->developerConfig([]);

        $this->assertSame('D3 Creative', $developer['name']);
        $this->assertStringStartsWith('https://d3creative.uk/', $developer['url']);
        $this->assertSame('support@d3creative.uk', $developer['email']);
    }

    public function test_a_white_label_name_alone_drops_d3s_link_and_email(): void
    {
        $developer = $this->developerConfig(['SENTINEL_DEV_NAME' => 'Acme Agency']);

        $this->assertSame('Acme Agency', $developer['name']);
        $this->assertNull($developer['url']);
        $this->assertNull($developer['email']);
    }

    public function test_a_white_label_link_and_email_are_used(): void
    {
        $developer = $this->developerConfig([
            'SENTINEL_DEV_NAME'  => 'Acme Agency',
            'SENTINEL_DEV_URL'   => 'https://acme.test',
            'SENTINEL_DEV_EMAIL' => 'help@acme.test',
        ]);

        $this->assertSame('https://acme.test', $developer['url']);
        $this->assertSame('help@acme.test', $developer['email']);
    }

    protected function developerConfig(array $env): array
    {
        foreach ($env as $var => $value) {
            putenv("{$var}={$value}");
            $_ENV[$var] = $_SERVER[$var] = $value;
        }

        return (require __DIR__ . '/../../config/statamic-sentinel.php')['developer'];
    }
}
