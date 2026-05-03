<?php

namespace D3Creative\Sentinel\Services;

use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class HistoryService
{
    const RELATIVE_PATH       = 'statamic-sentinel/history.json';
    const TMP_PATH            = 'statamic-sentinel/history.json.tmp';
    const LAST_REPORT_PATH    = 'statamic-sentinel/last-update-report.json';
    const RETENTION_DAYS      = 365;

    /**
     * The seven non-timestamp fields used both for change detection and as the
     * snapshot's data payload.
     */
    const TRACKED_FIELDS = [
        'statamic',
        'laravel',
        'php',
        'composer_outdated',
        'npm_outdated',
        'composer_security_updates',
        'npm_security_updates',
        'composer_vulns',
        'npm_vulns',
    ];

    /**
     * Build a snapshot from the audit array and append it to the history file
     * if any tracked field differs from the most recent stored snapshot.
     *
     * Silent on failure — file-system errors must never break CP rendering.
     */
    public function recordIfChanged(array $audit): void
    {
        try {
            $snapshot = $this->buildSnapshot($audit);
            $entries  = $this->all();

            if (! empty($entries) && $this->matches($snapshot, $entries[0])) {
                return;
            }

            array_unshift($entries, $snapshot);
            $entries = $this->prune($entries);

            $this->write($entries);
        } catch (\Throwable $e) {
            // Silently fail
        }
    }

    /**
     * All history entries, newest first. Returns [] on any read failure.
     */
    public function all(): array
    {
        try {
            if (! Storage::disk('local')->exists(self::RELATIVE_PATH)) {
                return [];
            }

            $raw     = Storage::disk('local')->get(self::RELATIVE_PATH);
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Persist the last successfully-sent non-empty update report so a future
     * forced resend can replay it. Silent on failure.
     */
    public function rememberLastReport(array $report): void
    {
        try {
            Storage::disk('local')->put(
                self::LAST_REPORT_PATH,
                json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        } catch (\Throwable $e) {
            // Silent fail
        }
    }

    /**
     * The last stored update report, or null if nothing has been remembered.
     */
    public function lastReport(): ?array
    {
        try {
            if (! Storage::disk('local')->exists(self::LAST_REPORT_PATH)) {
                return null;
            }

            $raw     = Storage::disk('local')->get(self::LAST_REPORT_PATH);
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function buildSnapshot(array $audit): array
    {
        return [
            'recorded_at'               => Carbon::now()->toIso8601String(),
            'statamic'                  => $audit['statamic']['current'] ?? null,
            'laravel'                   => $audit['laravel']['version']  ?? null,
            'php'                       => $audit['php']['version']      ?? null,
            'composer_outdated'         => (int) ($audit['composer']['outdated']['total']                  ?? 0),
            'npm_outdated'              => (int) ($audit['npm']['outdated']['total']                       ?? 0),
            'composer_security_updates' => (int) ($audit['composer']['outdated']['security_updates_total'] ?? 0),
            'npm_security_updates'      => (int) ($audit['npm']['outdated']['security_updates_total']      ?? 0),
            'composer_vulns'            => (int) ($audit['composer']['total_vulns']                        ?? 0),
            'npm_vulns'                 => (int) ($audit['npm']['total_vulns']                             ?? 0),
            // Per-package installed versions, used by the update report to diff
            // two snapshots (e.g. "statamic/cms 6.15.0 → 6.16.0"). Payload-only
            // — not part of TRACKED_FIELDS, so doesn't drive change detection.
            'composer_packages'         => $audit['composer']['installed'] ?? [],
            'npm_packages'              => $audit['npm']['installed']      ?? [],
        ];
    }

    protected function matches(array $a, array $b): bool
    {
        foreach (self::TRACKED_FIELDS as $field) {
            if (($a[$field] ?? null) !== ($b[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    protected function prune(array $entries): array
    {
        $cutoff = Carbon::now()->subDays(self::RETENTION_DAYS);

        return array_values(array_filter($entries, function ($entry) use ($cutoff) {
            try {
                return Carbon::parse($entry['recorded_at'])->greaterThanOrEqualTo($cutoff);
            } catch (\Throwable $e) {
                return false;
            }
        }));
    }

    protected function write(array $entries): void
    {
        $disk = Storage::disk('local');
        $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $disk->put(self::TMP_PATH, $json);

        if ($disk->exists(self::RELATIVE_PATH)) {
            $disk->delete(self::RELATIVE_PATH);
        }

        $disk->move(self::TMP_PATH, self::RELATIVE_PATH);
    }
}
