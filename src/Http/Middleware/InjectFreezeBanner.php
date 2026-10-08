<?php

namespace D3Creative\Sentinel\Http\Middleware;

use Closure;
use D3Creative\Sentinel\Services\ContentFreezeService;
use D3Creative\Sentinel\Support\CpAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Injects the content-freeze banner / modal into every CP HTML response
 * when there's an active, upcoming, or recently-completed freeze.
 *
 * Two injection strategies, picked by what's in the response HTML:
 *
 *  1. Statamic 5: the layout is Blade-rendered, so the
 *     `<div class="workspace">` is present in the response. Banner is
 *     injected as its first child - normal document flow, sits below
 *     the fixed `.global-header`. Every navigation is a full page load,
 *     so the banner is always current.
 *
 *  2. Statamic 6: the CP is rendered client-side via Inertia/Vue, so the
 *     initial HTML response only contains `<div id="statamic" data-page="...">`
 *     with no workspace / header / main in the markup. Banner is appended
 *     before `</body>` wrapped in a `position:fixed; top:0` overlay, and a
 *     small inline script shifts the Vue-rendered header and `#main` down
 *     by the banner's height. Navigations after the first load are Inertia
 *     JSON responses that never pass through here, so the overlay is added
 *     on every full load (empty when there's no freeze) and the script keeps
 *     it current: each Inertia response carries the banner key (shared below
 *     as `sentinelFreeze`), and when it differs from the one on screen the
 *     script fetches the new markup from the freeze-banner route.
 *
 * Silent on any failure - the CP render must never break because the
 * banner couldn't be assembled.
 */
class InjectFreezeBanner
{
    public function handle(Request $request, Closure $next): Response
    {
        $this->shareBannerKey();

        $response = $next($request);

        if (! $this->shouldInject($request, $response)) {
            return $response;
        }

        try {
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
            // attribute alone so we tolerate any whitespace and attribute
            // ordering. It must be a linear scan: Statamic 6 puts the whole
            // page (often over 1 MB) in the same tag's data-page attribute,
            // and a `<div[^>]*` prefix backtracked across all of it, hit
            // pcre.backtrack_limit and silently dropped the banner. Escaped
            // content can't match, since its quotes are &quot; or \".
            if (! preg_match('/\sid\s*=\s*"statamic"/i', $content)) {
                return $response;
            }

            // State machine advancement is handled by the AdvanceFreezeState
            // middleware which runs on every CP request (HTML or Inertia
            // JSON). This middleware only renders the banner markup.
            $markup = view('statamic-sentinel::cp.freeze-injector')->render();

            // Statamic 5: first child of <div class="workspace">, which it
            // renders inside #main, below the .global-header. Anchored on
            // class as the tag's first attribute (as Statamic 5 renders it)
            // so it can't backtrack across the shell's large attributes;
            // extra classes are tolerated.
            if (preg_match('/<div\s+class\s*=\s*"[^"<>]*\bworkspace\b[^"<>]*"[^>]*>/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
                if (trim($markup) === '') {
                    return $response;
                }

                $insertAt = $matches[0][1] + strlen($matches[0][0]);
                $response->setContent(
                    substr($content, 0, $insertAt) . $markup . substr($content, $insertAt)
                );

                return $response;
            }

            // Statamic 6: inject before the last </body>, even with no
            // banner, so the script is there to show one that appears later
            // in this session.
            //
            // Without a wrapper the banner ends up after the full-viewport
            // `#statamic` and is off-screen, so wrap it in a fixed overlay
            // at top:0 and ship an inline script that shifts the Vue-rendered
            // global header + `#main` down by the overlay's height.
            $pos = strripos($content, '</body>');

            if ($pos === false) {
                return $response;
            }

            $state    = app(ContentFreezeService::class)->bannerState();
            $endpoint = $this->bannerEndpoint();

            // Override the global `[x-cloak]{display:none}` rule inside the
            // overlay so the banner stays visible even if Alpine never
            // processes it (e.g. a JS error earlier in the Statamic bundle
            // aborts boot before Alpine.start). Only x-cloak is overridden,
            // not x-show - x-show needs to keep working for the dismissible
            // green completed-freeze banner, and without Alpine its absence
            // of inline `display:none` leaves the banner visible anyway.
            $overlay = '<style>#d3-sentinel-freeze-overlay [x-cloak]{display:block !important;}</style>'
                . '<div id="d3-sentinel-freeze-overlay"'
                . ' data-key="' . e($state['key']) . '"'
                . ' data-transition-at="' . e((string) ($state['transition_at'] ?? '')) . '"'
                . ' data-endpoint="' . e($endpoint) . '"'
                . ' style="position:fixed; top:0; left:0; right:0; z-index:9999;">'
                . $markup
                . '</div>'
                . $this->overlayScript();

            $response->setContent(
                substr($content, 0, $pos) . $overlay . substr($content, $pos)
            );
        } catch (\Throwable $e) {
            // Silent fail.
        }

        return $response;
    }

    /**
     * Add the banner key to every Inertia response in the CP (Statamic 6),
     * so the overlay script can tell after each navigation whether the
     * banner has changed without making a request of its own. Resolved
     * lazily, only when an Inertia page is actually rendered.
     */
    protected function shareBannerKey(): void
    {
        try {
            if (! class_exists(\Inertia\Inertia::class)) {
                return;
            }

            \Inertia\Inertia::share('sentinelFreeze', function () {
                try {
                    return app(CpAccess::class)->allows()
                        ? app(ContentFreezeService::class)->bannerState()['key']
                        : null;
                } catch (\Throwable $e) {
                    return null;
                }
            });
        } catch (\Throwable $e) {
            // No Inertia, no shared key: the banner just won't update.
        }
    }

    protected function bannerEndpoint(): string
    {
        try {
            return (string) cp_route('d3-sentinel.freeze.banner');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Inline script for the Statamic 6 overlay. Two jobs:
     *
     * - Push the Vue-rendered global header, `#main` and the nav down by the
     *   overlay's height, re-applying as Inertia re-renders them. With no
     *   banner it leaves the layout alone.
     * - Keep the banner current. After each Inertia navigation it compares
     *   the shared `sentinelFreeze` key with the overlay's and, when they
     *   differ, fetches the new markup and swaps it in (Alpine's own
     *   mutation observer starts the new x-data). For an upcoming freeze it
     *   also checks once at freeze_at, so an editor who stays on one page
     *   still sees the amber banner. It never polls on a timer: every CP
     *   request refreshes the session (Statamic exempts only its own
     *   session-timeout check), so polling would stop idle tabs timing out.
     *   It never reloads the page either, which would lose unsaved edits.
     */
    protected function overlayScript(): string
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

        var shifted = false;
        var observer = null;

        var apply = function () {
            var h = overlay.offsetHeight || 0;

            // No banner and nothing to undo: leave Statamic's layout alone.
            if (! h && ! shifted) return;

            var hdr = document.querySelector('#statamic header.fixed.top-0')
                || document.querySelector('header.fixed.top-0');
            if (hdr) hdr.style.top = h ? h + 'px' : '';
            var main = document.getElementById('main');
            if (main) main.style.top = h ? 'calc(3.5rem + ' + h + 'px)' : '';
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
                nav.style.top = h ? 'calc(3.5rem + ' + h + 'px)' : '';
                nav.style.height = h ? 'calc(100vh - 3.5rem - ' + h + 'px)' : '';
                void nav.offsetHeight;
                nav.style.transition = prevTransition;
            }

            shifted = h > 0;

            // Inertia re-creates the header and #main, so watch for that
            // only while there's a shift to keep.
            if (shifted && ! observer && window.MutationObserver) {
                observer = new MutationObserver(schedule);
                observer.observe(document.body, { childList: true, subtree: true });
            } else if (! shifted && observer) {
                observer.disconnect();
                observer = null;
            }
        };

        apply();
        if (window.ResizeObserver) new ResizeObserver(schedule).observe(overlay);
        // Vue may not have mounted yet on first paint; nudge a few times.
        setTimeout(apply, 100);
        setTimeout(apply, 500);
        setTimeout(apply, 1500);

        var endpoint = overlay.getAttribute('data-endpoint');
        var busy = false;
        var timer = null;
        var waitingForTab = false;
        var attempts = 0;
        var maxWait = 6 * 60 * 60 * 1000;

        var refresh = function () {
            if (busy || ! endpoint || ! window.fetch) return;
            busy = true;
            fetch(endpoint, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (res) { return res.ok ? res.json() : null; })
                .then(function (data) {
                    if (data && typeof data.key === 'string') render(data);
                })
                .catch(function () {})
                .then(function () { busy = false; });
        };

        var render = function (data) {
            if (data.key !== overlay.getAttribute('data-key')) {
                overlay.setAttribute('data-key', data.key);
                overlay.innerHTML = typeof data.html === 'string' ? data.html : '';
                attempts = 0;
                schedule();
            }
            arm(typeof data.transition_at === 'string' ? data.transition_at : '');
        };

        // One check at an upcoming freeze's start time. If the server hasn't
        // switched it on yet (the scheduler can lag a minute), retry every
        // 30 seconds, a few times at most. A hidden tab waits until it's
        // looked at again.
        var arm = function (iso) {
            clearTimeout(timer);
            timer = null;
            waitingForTab = false;

            var at = iso ? Date.parse(iso) : NaN;
            if (isNaN(at) || attempts >= 4) return;

            var wait = at - Date.now();
            wait = wait > 0 ? wait + 3000 : 30000;

            // setTimeout can't wait weeks; re-arm without asking the server.
            if (wait > maxWait) {
                timer = setTimeout(function () { arm(iso); }, maxWait);
                return;
            }

            timer = setTimeout(function () {
                attempts++;
                if (document.visibilityState === 'hidden') {
                    waitingForTab = true;
                    return;
                }
                refresh();
            }, wait);
        };

        document.addEventListener('inertia:navigate', function (e) {
            var page = e && e.detail ? e.detail.page : null;
            var key = page && page.props ? page.props.sentinelFreeze : null;
            if (typeof key === 'string' && key !== overlay.getAttribute('data-key')) refresh();
        });

        document.addEventListener('visibilitychange', function () {
            if (waitingForTab && document.visibilityState === 'visible') {
                waitingForTab = false;
                refresh();
            }
        });

        arm(overlay.getAttribute('data-transition-at') || '');
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

        if (! app(CpAccess::class)->allows()) {
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
