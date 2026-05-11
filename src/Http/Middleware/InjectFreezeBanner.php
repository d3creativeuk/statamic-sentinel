<?php

namespace D3Creative\Sentinel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Injects the content-freeze banner / modal into the bottom of every CP HTML
 * response when there's an active or recently-completed freeze. Operates on
 * the rendered response so it works across the full Statamic 3.3 -> 6.x
 * range without depending on layout-specific push stacks.
 *
 * Silent on any failure - the CP render must never break because the banner
 * couldn't be assembled.
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
            $markup = view('statamic-sentinel::cp.freeze-injector')->render();

            if (trim($markup) === '') {
                return $response;
            }

            $content = $response->getContent();

            if (! is_string($content) || $content === '') {
                return $response;
            }

            // Inject before the last </body> only - some CP responses include
            // markup with literal "</body>" inside a textarea or pre block.
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
