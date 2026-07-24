<?php

namespace D3Creative\Sentinel\Services;

use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

/**
 * Records the last time each CP user was active, as a `[user_id => iso8601]`
 * map. Written by the RecordLastActive middleware (throttled to ~1/min per
 * user) and read by the utility's Users tab to show who's online.
 *
 * Stored as JSON on the local disk so it survives `cache:clear`. Silent on
 * failure - activity tracking must never break a CP request or CP rendering.
 */
class LastActiveService
{
    const RELATIVE_PATH  = 'statamic-sentinel/last-active.json';
    const TMP_PATH       = 'statamic-sentinel/last-active.json.tmp';

    // Entries older than this are dropped, so the file can't grow unbounded and
    // long-departed users age out. Recent activity is all the Users tab needs;
    // older "last seen" info isn't worth retaining.
    const RETENTION_DAYS = 30;

    /**
     * The `[user_id => iso8601]` activity map, newest write wins. Returns [] on
     * any read failure.
     */
    public function all(): array
    {
        try {
            if (! Storage::disk('local')->exists(self::RELATIVE_PATH)) {
                return [];
            }

            $decoded = json_decode(Storage::disk('local')->get(self::RELATIVE_PATH), true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Stamp a user as active now, prune stale entries, and persist. Silent on
     * failure.
     */
    public function touch(string $userId): void
    {
        try {
            $entries = $this->all();
            $entries[$userId] = Carbon::now()->toIso8601String();

            $this->write($this->prune($entries));
        } catch (\Throwable $e) {
            // Silent fail - bookkeeping only.
        }
    }

    /**
     * True when the given ISO-8601 timestamp is within the last $windowMinutes.
     * Static + null-safe so the view (and tests) can call it without Statamic.
     */
    public static function isOnline(?string $iso, int $windowMinutes): bool
    {
        if (! $iso) {
            return false;
        }

        try {
            return Carbon::parse($iso)->greaterThan(Carbon::now()->subMinutes(max(1, $windowMinutes)));
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function prune(array $entries): array
    {
        $cutoff = Carbon::now()->subDays(self::RETENTION_DAYS);

        return array_filter($entries, function ($iso) use ($cutoff) {
            try {
                return Carbon::parse($iso)->greaterThanOrEqualTo($cutoff);
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    /**
     * Atomically replace the file (tmp + rename), mirroring HistoryService.
     */
    protected function write(array $entries): void
    {
        $disk = Storage::disk('local');
        $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $disk->put(self::TMP_PATH, $json);

        if (! $disk->move(self::TMP_PATH, self::RELATIVE_PATH)) {
            $disk->delete(self::RELATIVE_PATH);
            $disk->move(self::TMP_PATH, self::RELATIVE_PATH);
        }
    }
}
