<?php

namespace D3Creative\Sentinel\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * Records every Sentinel email actually delivered (or attempted), so the
 * Status Report and Update Report tabs can list what went out and let the
 * user re-open the exact rendered HTML that landed in inboxes.
 *
 * Storage layout under the local disk:
 *   statamic-sentinel/sent/index.json   small manifest of all records
 *   statamic-sentinel/sent/{id}.html    per-send HTML snapshot
 *
 * Cap is per-kind (status / update) so a busy schedule on one report can't
 * starve the other. Silent on failure - file-system errors must never break
 * a send or break CP rendering.
 */
class SentMailService
{
    const KIND_STATUS = 'status';
    const KIND_UPDATE = 'update';

    const TRIGGER_MANUAL    = 'manual';
    const TRIGGER_SCHEDULED = 'scheduled';
    const TRIGGER_FORCED    = 'forced';

    const OUTCOME_SENT   = 'sent';
    const OUTCOME_FAILED = 'failed';

    const DIR        = 'statamic-sentinel/sent';
    const INDEX_PATH = 'statamic-sentinel/sent/index.json';
    const TMP_PATH   = 'statamic-sentinel/sent/index.json.tmp';

    /**
     * Rolling cap per kind. Older records (and their HTML files) are pruned
     * once a kind exceeds this.
     */
    const MAX_PER_KIND = 25;

    /**
     * IDs are produced by Str::random(16). Anything else is rejected before
     * touching the filesystem - defends against path traversal in $id.
     */
    const ID_PATTERN = '/^[A-Za-z0-9]{16}$/';

    /**
     * Per-instance memo of the index file so a single request that calls
     * forKind() / find() multiple times doesn't re-read + re-decode the JSON.
     */
    protected ?array $cachedAll = null;

    protected static function isValidId(string $id): bool
    {
        return (bool) preg_match(self::ID_PATTERN, $id);
    }

    /**
     * Persist a sent-email record. Stores the manifest entry plus the HTML
     * snapshot; returns the new id (or null on failure).
     */
    public function record(
        string $kind,
        array $recipients,
        string $trigger,
        string $outcome,
        string $html = '',
        ?string $error = null
    ): ?string {
        try {
            $id = Str::random(16);

            $entry = [
                'id'          => $id,
                'recorded_at' => Carbon::now()->toIso8601String(),
                'kind'        => $kind,
                'recipients'  => array_values($recipients),
                'trigger'     => $trigger,
                'outcome'     => $outcome,
                'error'       => $error,
            ];

            $disk = Storage::disk('local');

            if ($html !== '') {
                $disk->put(self::DIR . '/' . $id . '.html', $html);
            }

            $entries = $this->all();
            array_unshift($entries, $entry);
            $entries = $this->prune($entries);

            $this->writeIndex($entries);

            return $id;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * All records, newest first. Returns [] on any read failure. Memoized
     * per request so repeated callers (e.g. forKind('status') + forKind('update'))
     * share a single decode.
     */
    public function all(): array
    {
        if ($this->cachedAll !== null) {
            return $this->cachedAll;
        }

        try {
            $disk = Storage::disk('local');

            if (! $disk->exists(self::INDEX_PATH)) {
                return $this->cachedAll = [];
            }

            $raw     = $disk->get(self::INDEX_PATH);
            $decoded = json_decode($raw, true);

            return $this->cachedAll = is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            return $this->cachedAll = [];
        }
    }

    /**
     * Records for a single kind, newest first. Used by the utility tabs.
     */
    public function forKind(string $kind): array
    {
        return array_values(array_filter(
            $this->all(),
            fn ($entry) => ($entry['kind'] ?? null) === $kind
        ));
    }

    /**
     * Look up a single record by id. Null if not found or id is malformed.
     */
    public function find(string $id): ?array
    {
        if (! self::isValidId($id)) {
            return null;
        }

        foreach ($this->all() as $entry) {
            if (($entry['id'] ?? null) === $id) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Read the stored HTML snapshot for a record. Null if the file is gone
     * (e.g. pruned, or send failed before render) or id is malformed.
     */
    public function html(string $id): ?string
    {
        if (! self::isValidId($id)) {
            return null;
        }

        try {
            $disk = Storage::disk('local');
            $path = self::DIR . '/' . $id . '.html';

            if (! $disk->exists($path)) {
                return null;
            }

            return $disk->get($path);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Remove a single sent-email record by id, plus its HTML snapshot file.
     * Returns true if a row was removed, false if the id matched nothing or
     * the write failed.
     */
    public function delete(string $id): bool
    {
        if (! self::isValidId($id)) {
            return false;
        }

        try {
            $entries = $this->all();
            $before  = count($entries);

            $entries = array_values(array_filter(
                $entries,
                fn ($e) => ($e['id'] ?? null) !== $id
            ));

            if (count($entries) === $before) {
                return false;
            }

            $this->writeIndex($entries);
            $this->deleteHtml($id);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Trim each kind to MAX_PER_KIND records, deleting the HTML files for
     * anything that drops off. Order is preserved (newest first).
     */
    protected function prune(array $entries): array
    {
        $kept    = [];
        $counts  = [];
        $dropped = [];

        foreach ($entries as $entry) {
            $kind = $entry['kind'] ?? 'unknown';
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;

            if ($counts[$kind] > self::MAX_PER_KIND) {
                $dropped[] = $entry;
                continue;
            }

            $kept[] = $entry;
        }

        foreach ($dropped as $entry) {
            if (! empty($entry['id'])) {
                $this->deleteHtml($entry['id']);
            }
        }

        return $kept;
    }

    /**
     * Unlink the per-send HTML snapshot for `$id`. Silent on failure -
     * leftover HTML is harmless and never blocks the calling operation.
     * Refuses anything that isn't a valid Sentinel id, defending against
     * path traversal even if a malformed id ever made it into the index.
     */
    protected function deleteHtml(string $id): void
    {
        if (! self::isValidId($id)) {
            return;
        }

        try {
            $disk = Storage::disk('local');
            $path = self::DIR . '/' . $id . '.html';

            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        } catch (\Throwable $e) {
            // Silent fail
        }
    }

    /**
     * Atomically replace the index file. On POSIX, rename overwrites in one
     * step (no readers see a missing file). On Windows, rename can't replace,
     * so we fall back to delete-then-move only if the first move fails.
     */
    protected function writeIndex(array $entries): void
    {
        $disk = Storage::disk('local');
        $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $disk->put(self::TMP_PATH, $json);

        if (! $disk->move(self::TMP_PATH, self::INDEX_PATH)) {
            $disk->delete(self::INDEX_PATH);
            $disk->move(self::TMP_PATH, self::INDEX_PATH);
        }

        $this->cachedAll = $entries;
    }
}
