<?php

namespace D3Creative\Sentinel\Support;

use D3Creative\Sentinel\Services\AuditService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The Scan Now / Refresh links: `?d3_refresh=<token>` on the dashboard or the
 * utility runs a full scan, then redirects to the same URL without it.
 *
 * A scan is dozens to hundreds of outbound requests and runs inside the page
 * request, so a plain `?d3_refresh=1` was a cheap way to tie up PHP workers:
 * any user who could see the widget could trigger one, a link on another site
 * would too (a top-level GET carries the CP session cookie), and nothing
 * stopped repeats. Now a scan needs a user with Sentinel access, a token tied
 * to their session, no other manual scan running and none in the last minute.
 * Anything else just gets the redirect.
 */
class ManualScan
{
    const PARAM            = 'd3_refresh';
    const LOCK             = 'sentinel_manual_scan';
    const LOCK_SECONDS     = 300;
    const LAST_SCAN_KEY    = 'd3creative_sentinel_last_manual_scan';
    const COOLDOWN_SECONDS = 60;

    /**
     * Only users who can open the Sentinel utility see its data.
     */
    public static function userCanView(): bool
    {
        try {
            $user = \Statamic\Facades\User::current();

            return $user !== null && ($user->isSuper() || $user->hasPermission('access sentinel utility'));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Value for the link's `d3_refresh` parameter. Derived from the session's
     * CSRF token (never the token itself, which shouldn't sit in URLs and
     * logs), so another site can't build a working link.
     */
    public static function token(): string
    {
        try {
            return hash_hmac('sha256', 'sentinel-manual-scan', (string) csrf_token());
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * When the request asks for a scan, maybe run one, then redirect to the
     * URL without the parameter. Returns normally when it didn't ask.
     */
    public function handle(Request $request, AuditService $audit): void
    {
        if (! $request->has(self::PARAM)) {
            return;
        }

        if ($this->allowed((string) $request->query(self::PARAM))) {
            $this->runOnce($audit);
        }

        throw new HttpResponseException(redirect()->to($request->fullUrlWithoutQuery(self::PARAM)));
    }

    protected function allowed(string $given): bool
    {
        $expected = static::token();

        return $expected !== ''
            && hash_equals($expected, $given)
            && static::userCanView()
            && ! $this->coolingDown();
    }

    protected function coolingDown(): bool
    {
        try {
            $last = Cache::get(self::LAST_SCAN_KEY);
        } catch (\Throwable $e) {
            return false;
        }

        return is_numeric($last) && (time() - (int) $last) < self::COOLDOWN_SECONDS;
    }

    /**
     * Skip when another manual scan holds the lock. A store without lock
     * support runs the scan unguarded, as before.
     */
    protected function runOnce(AuditService $audit): void
    {
        try {
            $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);

            if (! $lock->get()) {
                return;
            }
        } catch (\Throwable $e) {
            $lock = null;
        }

        try {
            try {
                Cache::put(self::LAST_SCAN_KEY, time(), self::COOLDOWN_SECONDS);
            } catch (\Throwable $e) {
                // Cooldown is best-effort.
            }

            $audit->refresh();
        } finally {
            optional($lock)->release();
        }
    }
}
