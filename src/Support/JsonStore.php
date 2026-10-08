<?php

namespace D3Creative\Sentinel\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Reads one of Sentinel's JSON stores from the local disk.
 *
 * For display, anything wrong (missing, unreadable, corrupt) reads as an
 * empty store so the CP still renders.
 *
 * For a read-modify-write that's not safe: the stores used to read a corrupt
 * or unreadable file as empty, and the next routine write replaced it with
 * just the newest entry, turning a recoverable file into permanent loss.
 * With $forWrite:
 *
 *  - missing: empty store, as before
 *  - can't be read: throws, so the write aborts and the file is left alone
 *  - present but not valid JSON: moved aside to `<name>.corrupt-<time>` and
 *    logged, so its bytes can be recovered, then treated as empty
 */
class JsonStore
{
    public static function read(string $path, bool $forWrite = false): array
    {
        if (! $forWrite) {
            try {
                return static::decode($path) ?? [];
            } catch (\Throwable $e) {
                return [];
            }
        }

        $decoded = static::decode($path, $raw);

        if ($decoded === null && is_string($raw) && trim($raw) !== '') {
            static::quarantine($path);
        }

        return $decoded ?? [];
    }

    /**
     * Null when the file is missing or isn't a JSON object/array; $raw holds
     * what was read. Throws when the file exists but can't be read.
     */
    protected static function decode(string $path, &$raw = null): ?array
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            return null;
        }

        $raw = $disk->get($path);

        if (! is_string($raw)) {
            throw new \RuntimeException("Could not read {$path}");
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    protected static function quarantine(string $path): void
    {
        $aside = $path . '.corrupt-' . date('YmdHis');

        try {
            // A new name, so this is a plain rename on any adapter.
            if (! Storage::disk('local')->move($path, $aside)) {
                throw new \RuntimeException("Could not move {$path} aside");
            }

            Log::warning("Sentinel found {$path} unreadable as JSON and moved it to {$aside} before writing a new one.");
        } catch (\Throwable $e) {
            // Better to abort the write than to replace a file we couldn't
            // keep a copy of.
            throw new \RuntimeException("Could not move corrupt {$path} aside", 0, $e);
        }
    }
}
