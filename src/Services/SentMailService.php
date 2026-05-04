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
     * All records, newest first. Returns [] on any read failure.
     */
    public function all(): array
    {
        try {
            $disk = Storage::disk('local');

            if (! $disk->exists(self::INDEX_PATH)) {
                return [];
            }

            $raw     = $disk->get(self::INDEX_PATH);
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            return [];
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
     * Look up a single record by id. Null if not found.
     */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $entry) {
            if (($entry['id'] ?? null) === $id) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Read the stored HTML snapshot for a record. Null if the file is gone
     * (e.g. pruned, or send failed before render).
     */
    public function html(string $id): ?string
    {
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

        $disk = Storage::disk('local');
        foreach ($dropped as $entry) {
            $path = self::DIR . '/' . ($entry['id'] ?? '') . '.html';
            try {
                if (! empty($entry['id']) && $disk->exists($path)) {
                    $disk->delete($path);
                }
            } catch (\Throwable $e) {
                // Silent fail - leftover HTML is harmless.
            }
        }

        return $kept;
    }

    protected function writeIndex(array $entries): void
    {
        $disk = Storage::disk('local');
        $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $disk->put(self::TMP_PATH, $json);

        if ($disk->exists(self::INDEX_PATH)) {
            $disk->delete(self::INDEX_PATH);
        }

        $disk->move(self::TMP_PATH, self::INDEX_PATH);
    }
}
