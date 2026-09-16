<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Tests\TestCase;

/**
 * Statamic 3.3-5 mount Vue 2 on the server-rendered #statamic markup, so Vue
 * compiles the utility and widget HTML as a template. Blade's `{{ }}` escapes
 * HTML but not Vue's `{{ }}`, so a CP user whose name is
 * `{{ constructor.constructor('...')() }}` would run code in the session of
 * any super who opens Sentinel. `v-pre` on the single root element stops Vue
 * compiling the subtree; these tests keep it there and keep all markup inside
 * that root.
 */
class ViewTemplateInjectionTest extends TestCase
{
    public function test_utility_view_markup_is_wrapped_in_a_v_pre_root(): void
    {
        $source = $this->viewSource('utilities/sentinel.blade.php');

        $this->assertSame(1, preg_match("/@section\('content'\)(.*)@endsection/s", $source, $m));

        $this->assertWrappedInVPreRoot($m[1]);
    }

    public function test_widget_view_markup_is_wrapped_in_a_v_pre_root(): void
    {
        $source = preg_replace('/@php.*?@endphp/s', '', $this->viewSource('widgets/sentinel.blade.php'));

        $this->assertWrappedInVPreRoot($source);
    }

    protected function viewSource(string $path): string
    {
        $source = file_get_contents(__DIR__ . '/../../resources/views/' . $path);

        return preg_replace('/\{\{--.*?--\}\}/s', '', $source);
    }

    protected function assertWrappedInVPreRoot(string $markup): void
    {
        $markup = trim($markup);

        $this->assertMatchesRegularExpression('/^<div\s+v-pre[\s>]/', $markup, 'Root element must carry v-pre.');
        $this->assertStringEndsWith('</div>', $markup);

        // The first <div> must be the one closed at the very end, so no
        // sibling markup sits outside the v-pre subtree.
        $depth = 0;
        preg_match_all('#<(/?)div\b#i', $markup, $tags, PREG_OFFSET_CAPTURE);

        foreach ($tags[1] as $i => [$slash, $offset]) {
            $depth += $slash === '/' ? -1 : 1;

            if ($depth === 0) {
                $this->assertSame(count($tags[1]) - 1, $i, 'Markup found outside the v-pre root element.');
            }
        }
    }
}
