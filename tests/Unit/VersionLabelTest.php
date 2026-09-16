<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Support\VersionLabel;
use D3Creative\Sentinel\Tests\TestCase;

/**
 * Version rows used to check "outdated" before "end of life". PHP's `latest`
 * spans every branch, so an EOL PHP always read as merely outdated, and the
 * widget printed only the latest version, in red, as if it were installed.
 */
class VersionLabelTest extends TestCase
{
    public function test_an_eol_version_with_an_update_shows_both(): void
    {
        $this->assertSame('8.0.30 → 8.5.7 (EOL)', VersionLabel::text('8.0.30', '8.5.7', 'eol'));
    }

    public function test_the_installed_version_is_always_shown(): void
    {
        $this->assertSame('6.0.0 → 6.5.0', VersionLabel::text('6.0.0', '6.5.0', 'outdated'));
        $this->assertSame('6.5.0', VersionLabel::text('6.5.0', '6.5.0', 'ok'));
        $this->assertSame('8.4.1', VersionLabel::text('8.4.1', null, 'active'));
    }

    public function test_eol_without_an_update(): void
    {
        $this->assertSame('11.2.0 (EOL)', VersionLabel::text('11.2.0', '11.2.0', 'eol'));
    }

    public function test_security_prefix_only_applies_when_an_update_exists(): void
    {
        $this->assertSame('Security: 11.0.0 → 13.0.0 (EOL)', VersionLabel::text('11.0.0', '13.0.0', 'eol', true));
        $this->assertSame('13.0.0', VersionLabel::text('13.0.0', '13.0.0', 'active', true));
    }
}
