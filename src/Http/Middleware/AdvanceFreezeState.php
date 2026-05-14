<?php

namespace D3Creative\Sentinel\Http\Middleware;

use Closure;
use D3Creative\Sentinel\Services\ContentFreezeService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fallback ticker for the content-freeze state machine. Runs once per CP
 * request and advances `scheduled -> notified -> active` whenever a
 * timestamp has passed.
 *
 * Production sites should rely on the every-minute `schedule:run` cron
 * entry that Sentinel registers in its service provider; this middleware
 * exists to keep the state machine moving in environments where cron
 * isn't wired up (local dev, Herd, shared hosting without scheduler
 * access). Cron and this middleware are both idempotent so they coexist
 * safely - whichever fires first wins the transition.
 *
 * Registered on the `statamic.cp` middleware group so it runs on every
 * CP request, including Inertia JSON navigations that the
 * InjectFreezeBanner middleware skips (it only processes HTML responses).
 * That distinction matters in Statamic 6, where most CP navigation is
 * Inertia-driven and never returns an HTML response after the first load.
 *
 * Silent on any failure - the CP must never break because a tick failed.
 */
class AdvanceFreezeState
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            app(ContentFreezeService::class)->tickIfDue();
        } catch (\Throwable $e) {
            // Silent fail.
        }

        return $next($request);
    }
}
