<?php

namespace D3Creative\Sentinel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Injects the content-freeze banner / modal into every CP HTML response
 * when there's an active, upcoming, or recently-completed freeze.
 *
 * Two injection strategies, picked by what's in the response HTML:
 *
 *  1. Statamic 3.3 / 4 / 5: the layout is Blade-rendered, so the
 *     `<div class="workspace">` is present in the response. Banner is
 *     injected as its first child - normal document flow, sits below
 *     the fixed `.global-header`.
 *
 *  2. Statamic 6: the CP is rendered client-side via Inertia/Vue, so the
 *     initial HTML response only contains `<div id="statamic" data-page="...">`
 *     with no workspace / header / main in the markup. Banner is appended
 *     before `</body>` wrapped in a `position:fixed; top:0` overlay, and a
 *     small inline script shifts the Vue-rendered header and `#main` down
 *     by the banner's height (re-applied on Inertia navigations via a
 *     MutationObserver). Without the shift the overlay would sit on top
 *     of the global header; without the overlay the banner ends up below
 *     the full-viewport `#statamic` and is invisible.
 *
 * Silent on any failure - the CP render must never break because the
 * banner couldn't be assembled.
 */
class InjectFreezeBanner
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldInject($request, $response)) {
            return $response;
        }

        try {
            // State machine advancement is handled by the AdvanceFreezeState
            // middleware which runs on every CP request (HTML or Inertia
            // JSON). This middleware only renders the banner markup, which
            // requires an HTML response.
            $markup = view('statamic-sentinel::cp.freeze-injector')->render();

            if (trim($markup) === '') {
                return $response;
            }

            $content = $response->getContent();

            if (! is_string($content) || $content === '') {
                return $response;
            }

            // Only inject into responses that contain the Statamic CP shell.
            // Preview-email iframe responses are full HTML documents served
            // under the CP route prefix, so they pass shouldInject() - but
            // they intentionally render bare email markup with no #statamic
            // wrapper. Without this guard the </body> fallback below ends up
            // injecting the freeze banner into the email preview iframe.
            //
            // Statamic 6's layout.blade.php uses multi-line attributes, so
            // the rendered shell is `<div\n    id="statamic"` (whitespace
            // between the tag name and the id attribute). Match the id
            // attribute alone via regex so we tolerate any whitespace and
            // attribute ordering.
            if (! preg_match('/<div\b[^>]*\bid\s*=\s*"statamic"/is', $content)) {
                return $response;
            }

            // Preferred injection point: first child of <div class="workspace">,
            // which Statamic renders inside #main, below the .global-header.
            // The regex tolerates extra classes and attribute ordering.
            if (preg_match('/<div\s[^>]*\bclass\s*=\s*"[^"]*\bworkspace\b[^"]*"[^>]*>/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
                $insertAt = $matches[0][1] + strlen($matches[0][0]);
                $response->setContent(
                    substr($content, 0, $insertAt) . $markup . substr($content, $insertAt)
                );

                return $response;
            }

            // Fallback: inject before the last </body>. Used on Statamic 6
            // where the CP is rendered client-side by Vue/Inertia and the
            // initial HTML response has no `.workspace` for us to target.
            //
            // Without a wrapper the banner ends up after the full-viewport
            // `#statamic` and is off-screen, so wrap it in a fixed overlay
            // at top:0 and ship an inline script that shifts the Vue-rendered
            // global header + `#main` down by the overlay's height. The
            // script keeps reapplying on Inertia navigations so the shift
            // survives page transitions.
            $pos = strripos($content, '</body>');

            if ($pos === false) {
                return $response;
            }

            // Override the global `[x-cloak]{display:none}` rule inside the
            // overlay so the banner stays visible even if Alpine never
            // processes it (e.g. a JS error earlier in the Statamic bundle
            // aborts boot before Alpine.start). Only x-cloak is overridden,
            // not x-show - x-show needs to keep working for the dismissible
            // green completed-freeze banner, and without Alpine its absence
            // of inline `display:none` leaves the banner visible anyway.
            $overlay = '<style>#d3-sentinel-freeze-overlay [x-cloak]{display:block !important;}</style>'
                . '<div id="d3-sentinel-freeze-overlay" style="position:fixed; top:0; left:0; right:0; z-index:9999;">'
                . $markup
                . '</div>'
                . $this->shiftScript();

            $response->setContent(
                substr($content, 0, $pos) . $overlay . substr($content, $pos)
            );
        } catch (\Throwable $e) {
            // Silent fail.
        }

        return $response;
    }

    /**
     * Inline script that pushes the Statamic 6 Vue-rendered global header
     * and `#main` down by the freeze overlay's height. Re-runs on Inertia
     * navigation because Vue re-creates those nodes; the parent check on
     * the banner makes apply() idempotent.
     */
    protected function shiftScript(): string
    {
        return <<<'HTML'
<script>
(function () {
    try {
        var overlay = document.getElementById('d3-sentinel-freeze-overlay');
        if (! overlay) return;

        // MutationObserver fires on every DOM change in the CP (Inertia
        // navigation, Alpine toggles, Statamic toasts). Collapse calls into
        // one per animation frame so apply() doesn't force a style recalc
        // dozens of times per second on a busy page.
        var scheduled = false;
        var schedule = function () {
            if (scheduled) return;
            scheduled = true;
            (window.requestAnimationFrame || function (cb) { setTimeout(cb, 16); })(function () {
                scheduled = false;
                apply();
            });
        };

        var apply = function () {
            var h = overlay.offsetHeight || 0;
            if (! h) return;
            var hdr = document.querySelector('#statamic header.fixed.top-0')
                || document.querySelector('header.fixed.top-0');
            if (hdr) hdr.style.top = h + 'px';
            var main = document.getElementById('main');
            if (main) main.style.top = 'calc(3.5rem + ' + h + 'px)';
            // Statamic 6's sidebar (`.nav-main`) is position:fixed with its
            // own `top: 3.5rem` and `height: calc(100vh - 3.5rem)`, so it
            // doesn't inherit `#main`'s shift. Push it down by `h` and
            // shrink its height so the first nav item (Dashboard) isn't
            // hidden behind the freeze banner.
            //
            // `.page-fully-loaded .nav-main` has `transition: inset .3s`,
            // which would animate the jump on every refresh. Suppress the
            // transition for the shift, force a reflow to commit the value
            // synchronously, then restore so Statamic's own nav slide-in
            // animations still work afterwards.
            var nav = document.querySelector('.nav-main');
            if (nav) {
                var prevTransition = nav.style.transition;
                nav.style.transition = 'none';
                nav.style.top = 'calc(3.5rem + ' + h + 'px)';
                nav.style.height = 'calc(100vh - 3.5rem - ' + h + 'px)';
                void nav.offsetHeight;
                nav.style.transition = prevTransition;
            }
        };

        apply();
        if (window.ResizeObserver) new ResizeObserver(schedule).observe(overlay);
        if (window.MutationObserver) {
            new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
        }
        // Vue may not have mounted yet on first paint; nudge a few times.
        setTimeout(apply, 100);
        setTimeout(apply, 500);
        setTimeout(apply, 1500);
    } catch (e) {}
})();
</script>
HTML;
    }

    protected function shouldInject(Request $request, Response $response): bool
    {
        if ($response->getStatusCode() !== 200) {
            return false;
        }

        $contentType = $response->headers->get('Content-Type', '');

        if (stripos($contentType, 'text/html') === false) {
            return false;
        }

        if (! auth()->check()) {
            return false;
        }

        $cpPrefix = trim((string) config('statamic.cp.route', 'cp'), '/');

        if ($cpPrefix === '') {
            return false;
        }

        // Match exact CP root and any sub-path - $request->is() supports
        // wildcards. The trim above prevents a leading slash in the env
        // config from breaking the wildcard match.
        return $request->is($cpPrefix) || $request->is($cpPrefix . '/*');
    }
}
