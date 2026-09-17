<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Tests\TestCase;

/**
 * Source guards for Alpine pitfalls the views have hit in Statamic's CP.
 */
class ViewAlpineTest extends TestCase
{
    /**
     * x-show sets display:none to hide and removes the inline display to show
     * again, so an inline display:flex on the same element is lost and the
     * spinner, labels or message row lose their layout. The flex belongs on
     * an inner element. (display:none is fine: it's the pre-Alpine state.)
     */
    public function test_x_show_elements_carry_no_inline_display(): void
    {
        foreach ($this->views() as $file => $source) {
            preg_match_all('/<[a-zA-Z]+\b[^>]*?\bx-show="[^"]*"[^>]*>/s', $source, $tags);

            foreach ($tags[0] as $tag) {
                if (preg_match('/style="[^"]*display:\s*([a-z-]+)/', $tag, $m)) {
                    $this->assertSame('none', $m[1], "{$file}: " . preg_replace('/\s+/', ' ', $tag));
                }
            }
        }
    }

    /**
     * Listeners added with window.addEventListener in init() survive Statamic
     * 6's Inertia unmounting the page, so they pile up on each visit. Alpine's
     * x-on:...window attributes are removed with the component.
     */
    public function test_views_do_not_add_window_listeners_by_hand(): void
    {
        foreach ($this->views() as $file => $source) {
            $this->assertStringNotContainsString("window.addEventListener('hashchange'", $source, $file);
        }
    }

    protected function views(): array
    {
        $views = [];

        foreach (glob(__DIR__ . '/../../resources/views/{,*/,*/*/}*.blade.php', GLOB_BRACE) as $file) {
            $views[basename(dirname($file)) . '/' . basename($file)] = file_get_contents($file);
        }

        return $views;
    }
}
