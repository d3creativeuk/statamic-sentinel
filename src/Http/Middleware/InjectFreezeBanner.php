<?php

namespace D3Creative\Sentinel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Injects the content-freeze banner / modal into every CP HTML response
 * when there's an active, upcoming, or recently-completed freeze.
 *
 * The banner is injected as the first child of `<div class="workspace">`
 * so it sits in normal document flow at the top of the CP's right-side
 * content area, just under the fixed `.global-header`. A fallback before
 * `</body>` runs for layouts without a `.workspace` element (e.g. the
 * Statamic 3.3 auth pages); the banner has no fixed positioning, so that
 * fallback just appends it at the end of the body.
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
            if (strpos($content, '<div id="statamic"') === false) {
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

            // Fallback: inject before the last </body>. The banner has no
            // fixed positioning, so it just appends at the end of the body
            // on layouts that don't expose a .workspace element. Safe to run
            // here because the #statamic shell check above already filtered
            // out preview iframes.
            $pos = strripos($content, '</body>');

            if ($pos === false) {
                return $response;
            }

            $response->setContent(
                substr($content, 0, $pos) . $markup . substr($content, $pos)
            );
        } catch (\Throwable $e) {
            // Silent fail.
        }

        return $response;
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
