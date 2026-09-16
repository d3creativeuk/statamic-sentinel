<?php

namespace D3Creative\Sentinel\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Atomically replace a file on the local disk: write a temp file beside it,
 * then rename it over the target so readers never see a missing or
 * half-written file.
 *
 * Storage::move() can't be trusted for that rename. On Laravel 8, Flysystem 1's
 * rename() throws FileExistsException when the target already exists, so
 * every write after a file's first one failed, and the old "delete then move
 * again" fallback never ran because the exception skipped it. PHP's own
 * rename() replaces the target in one step on POSIX and Windows alike, so it
 * does the swap and Storage::move() is only a fallback.
 *
 * The temp name is unique per write, so two overlapping writers can't consume
 * or delete each other's temp file. Throws when the file can't be replaced;
 * callers keep their own silent-fail handling.
 */
class AtomicFile
{
    public static function put(string $path, string $contents): void
    {
        $disk = Storage::disk('local');
        $tmp  = $path . '.' . Str::random(12) . '.tmp';

        $disk->put($tmp, $contents);

        try {
            if (static::renameOnFilesystem($disk, $tmp, $path)) {
                return;
            }

            try {
                if ($disk->move($tmp, $path)) {
                    return;
                }
            } catch (\Throwable $e) {
                // Target exists on Flysystem 1 - replace it below.
            }

            $disk->delete($path);

            if (! $disk->move($tmp, $path)) {
                throw new \RuntimeException("Could not replace {$path}");
            }
        } finally {
            try {
                if ($disk->exists($tmp)) {
                    $disk->delete($tmp);
                }
            } catch (\Throwable $e) {
                // Leftover temp file is harmless.
            }
        }
    }

    /**
     * Native rename for disks backed by the local filesystem. False when the
     * disk has no local path or the rename fails, so the caller can fall back.
     */
    protected static function renameOnFilesystem($disk, string $tmp, string $path): bool
    {
        try {
            $from = $disk->path($tmp);
            $to   = $disk->path($path);

            return is_file($from) && rename($from, $to);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
