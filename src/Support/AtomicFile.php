<?php

namespace D3Creative\Sentinel\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Atomically replace a file on the local disk: write a temp file beside it,
 * then rename it over the target so readers never see a missing or
 * half-written file.
 *
 * The temp write is checked before anything touches the target. Laravel's
 * local disk returns false from put() instead of throwing (the default
 * 'throw' => false), and on a full disk or quota it leaves a partial temp
 * behind; renaming that over the store, or deleting the store to make room,
 * silently wiped it. Now a failed write throws and the target is untouched.
 *
 * The temp name is unique per write, so two overlapping writers can't consume
 * or delete each other's temp file. Throws when the file can't be replaced;
 * callers keep their own silent-fail handling.
 */
class AtomicFile
{
    /**
     * Invalid UTF-8 (e.g. a Latin-1 SMTP reply stored as an error) becomes
     * U+FFFD instead of making json_encode() return false, which used to be
     * written over the store as an empty file. Anything else that can't be
     * encoded throws.
     */
    const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    public static function putJson(string $path, $data, int $extraFlags = 0): void
    {
        static::put($path, json_encode($data, self::JSON_FLAGS | $extraFlags));
    }

    public static function put(string $path, string $contents): void
    {
        // No Sentinel store is ever legitimately empty.
        if ($contents === '') {
            throw new \InvalidArgumentException("Refusing to write an empty {$path}");
        }

        $disk = Storage::disk('local');
        $tmp  = $path . '.' . Str::random(12) . '.tmp';

        try {
            if ($disk->put($tmp, $contents) === false) {
                throw new \RuntimeException("Could not write {$tmp}");
            }

            if (static::renameOnFilesystem($disk, $tmp, $path)) {
                return;
            }

            // A disk with no local path: move() replaces the target.
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
     * Native rename for disks backed by the local filesystem: replaces the
     * target in one step. False when the disk isn't on the local filesystem
     * (path() throws, or names no real file), so the caller can fall back to
     * move(). Throws when a local rename fails, rather than falling back to
     * something that could lose the target.
     */
    protected static function renameOnFilesystem($disk, string $tmp, string $path): bool
    {
        try {
            $from = $disk->path($tmp);
            $to   = $disk->path($path);
        } catch (\Throwable $e) {
            return false;
        }

        if (! is_file($from)) {
            return false;
        }

        if (! @rename($from, $to)) {
            throw new \RuntimeException("Could not replace {$path}");
        }

        return true;
    }
}
