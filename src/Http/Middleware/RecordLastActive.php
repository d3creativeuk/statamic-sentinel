<?php

namespace D3Creative\Sentinel\Http\Middleware;

use Closure;
use D3Creative\Sentinel\Services\LastActiveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records the authenticated CP user's last-active time on each request, so the
 * utility's Users tab can show who's recently online. Registered on the
 * `statamic.cp` group, after Statamic's own AuthGuard has run, so the CP user
 * is resolvable.
 *
 * A short-TTL cache key throttles this to one disk write per user per minute
 * (Cache::add is atomic write-if-absent), so a busy CP session never hammers
 * the JSON store. Silent on failure - tracking must never break a CP request.
 */
class RecordLastActive
{
    const THROTTLE_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if (config('statamic-sentinel.users.track_activity', true) && auth()->check()) {
                $id = \Statamic\Facades\User::current()?->id();

                if ($id !== null
                    && Cache::add('d3creative_sentinel_active_' . $id, true, now()->addSeconds(self::THROTTLE_SECONDS))
                ) {
                    app(LastActiveService::class)->touch((string) $id);
                }
            }
        } catch (\Throwable $e) {
            // Silent fail - bookkeeping only.
        }

        return $response;
    }
}
