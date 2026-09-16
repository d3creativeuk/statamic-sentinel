<?php

namespace D3Creative\Sentinel\Http\Middleware;

use Closure;
use D3Creative\Sentinel\Services\ContentFreezeService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fallback ticker for the content-freeze state machine. After each
 * authenticated CP request it advances `scheduled -> notified -> active`
 * whenever a timestamp has passed.
 *
 * Production sites should rely on the every-minute `schedule:run` cron
 * entry that Sentinel registers in its service provider; this middleware
 * exists to keep the state machine moving in environments where cron
 * isn't wired up (local dev, Herd, shared hosting without scheduler
 * access). Both paths share the freeze lock, so they coexist safely.
 *
 * Registered on the `statamic.cp` middleware group so it runs on every
 * CP request, including Inertia JSON navigations that the
 * InjectFreezeBanner middleware skips (it only processes HTML responses).
 * That distinction matters in Statamic 6, where most CP navigation is
 * Inertia-driven and never returns an HTML response after the first load.
 *
 * The tick runs in terminate(), after the response has been sent, and only
 * for signed-in users. On a sync queue the heads-up email is sent during
 * the tick, so running it in handle() made whoever loaded a page as
 * notify_at passed (even a guest on the login screen) wait for SMTP. The
 * banner can therefore trail the transition by one page load.
 *
 * Silent on any failure - the CP must never break because a tick failed.
 */
class AdvanceFreezeState
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        try {
            if (! auth()->check()) {
                return;
            }

            app(ContentFreezeService::class)->tickIfDue();
        } catch (\Throwable $e) {
            // Silent fail.
        }
    }
}
