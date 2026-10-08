<?php

namespace D3Creative\Sentinel\Services;

use D3Creative\Sentinel\Support\AtomicFile;
use D3Creative\Sentinel\Support\JsonStore;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

class HistoryService
{
    const RELATIVE_PATH       = 'statamic-sentinel/history.json';
    const LAST_REPORT_PATH    = 'statamic-sentinel/last-update-report.json';
    const RETENTION_DAYS      = 365;

    // Also capped by count: frequent scans on a flaky network could add
    // thousands of entries within the year, and the file is decoded whole
    // on every utility load and scan.
    const MAX_ENTRIES         = 500;

    /**
     * The non-timestamp fields used both for change detection and as the
     * snapshot's data payload.
     */
    const TRACKED_FIELDS = [
        'statamic',
        'laravel',
        'php',
        'license_status',
        'composer_outdated',
        'npm_outdated',
        'composer_security_updates',
        'npm_security_updates',
        'composer_vulns',
        'npm_vulns',
    ];

    /**
     * Per-package maps that also count as a change. Without them, a scan that
     * only moved package versions (staying behind the same newer major) or
     * swapped one vulnerable package for another left every total the same,
     * recorded nothing, and the next update report re-sent the previous diff.
     */
    const CHANGE_MAPS = [
        'composer_packages',
        'npm_packages',
        'composer_vuln_packages',
        'npm_vuln_packages',
    ];

    /**
     * Build a snapshot from the audit array and append it to the history file
     * if any tracked field differs from the most recent stored snapshot.
     *
     * Silent on failure - file-system errors must never break CP rendering.
     */
    public function recordIfChanged(array $audit): void
    {
        try {
            $entries  = JsonStore::read(self::RELATIVE_PATH, true);
            $snapshot = $this->carryForwardFailedChecks($this->buildSnapshot($audit), $audit, $entries[0] ?? null);

            if ($snapshot === null) {
                return;
            }

            if (! empty($entries) && $this->matches($snapshot, $entries[0])) {
                // Snapshots recorded before parents were stored lack them. Fill
                // them in from this scan of the same state, so the update
                // report that's already waiting can say "braces via
                // tailwindcss" and show a note saved on tailwindcss.
                $backfilled = false;

                foreach (['composer', 'npm'] as $eco) {
                    $key = "{$eco}_dependency_parents";

                    if (! array_key_exists($key, $entries[0]) && array_key_exists($key, $snapshot)) {
                        $entries[0][$key] = $snapshot[$key];
                        $backfilled = true;
                    }
                }

                if ($backfilled) {
                    $this->write($entries);
                }

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
     * Remove a single history entry by `id` (or `recorded_at` for legacy
     * entries written before ids existed). Returns true if a row was removed,
     * false if the key matched nothing or the write failed.
     */
    public function delete(string $key): bool
    {
        try {
            $entries = JsonStore::read(self::RELATIVE_PATH, true);
            $before  = count($entries);

            $entries = array_values(array_filter(
                $entries,
                fn ($e) => ($e['id'] ?? null) !== $key && ($e['recorded_at'] ?? null) !== $key
            ));

            if (count($entries) === $before) {
                return false;
            }

            $this->write($entries);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Persist the last successfully-sent non-empty update report so a future
     * forced resend can replay it. Silent on failure.
     */
    public function rememberLastReport(array $report): void
    {
        try {
            AtomicFile::putJson(self::LAST_REPORT_PATH, $report);
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
            'id'                        => Str::random(16),
            'recorded_at'               => Carbon::now()->toIso8601String(),
            'statamic'                  => $audit['statamic']['current'] ?? null,
            'laravel'                   => $audit['laravel']['version']  ?? null,
            'php'                       => $audit['php']['version']      ?? null,
            // Statamic license status (ok/renewal/invalid/trial/free/unknown), or
            // null on installs where licensing isn't available. A status change
            // (e.g. renewal -> ok after renewing) drives a new snapshot + shows
            // in the update report.
            'license_status'            => $audit['license']['status']   ?? null,
            'composer_outdated'         => (int) ($audit['composer']['outdated']['total']                  ?? 0),
            'npm_outdated'              => (int) ($audit['npm']['outdated']['total']                       ?? 0),
            'composer_security_updates' => (int) ($audit['composer']['outdated']['security_updates_total'] ?? 0),
            'npm_security_updates'      => (int) ($audit['npm']['outdated']['security_updates_total']      ?? 0),
            'composer_vulns'            => (int) ($audit['composer']['total_vulns']                        ?? 0),
            'npm_vulns'                 => (int) ($audit['npm']['total_vulns']                             ?? 0),
            // Per-package installed versions, used by the update report to diff
            // two snapshots (e.g. "statamic/cms 6.15.0 → 6.16.0"). Payload-only
            // - not part of TRACKED_FIELDS, so doesn't drive change detection.
            'composer_packages'         => $audit['composer']['installed'] ?? [],
            'npm_packages'              => $audit['npm']['installed']      ?? [],
            // Per-package vuln counts (`[name => count]`), so the update report
            // can name which packages had vulns resolved or introduced between
            // two snapshots, not just the total delta.
            'composer_vuln_packages'    => self::vulnPackageMap($audit['composer']['by_package'] ?? []),
            'npm_vuln_packages'         => self::vulnPackageMap($audit['npm']['by_package']      ?? []),
            // Per-package highest severity (`[name => 'CRITICAL'|'HIGH'|...]`), so
            // the maintenance report can break security-related updates down by
            // severity. Additive + forward-only: snapshots recorded before this
            // field simply lack it, and consumers handle its absence.
            'composer_vuln_severities'  => self::vulnSeverityMap($audit['composer']['by_package'] ?? []),
            'npm_vuln_severities'       => self::vulnSeverityMap($audit['npm']['by_package']      ?? []),
            // Vulnerable transitive package => the direct dependency that pulls
            // it in (`['braces' => 'tailwindcss']`), so the update report can
            // say "braces via tailwindcss". Payload-only and forward-only, like
            // the severities above.
            'composer_dependency_parents' => $audit['composer']['dependency_parents'] ?? [],
            'npm_dependency_parents'      => $audit['npm']['dependency_parents']      ?? [],
        ];
    }

    /**
     * A failed OSV lookup or registry pool reports zero vulnerabilities / zero
     * updates. Recording that would read as "all resolved" in the update and
     * plan reports, then "all introduced" after the next good scan. So for an
     * ecosystem whose check failed, keep the previous snapshot's figures. With
     * no previous snapshot to carry from, return null and record nothing.
     */
    protected function carryForwardFailedChecks(array $snapshot, array $audit, ?array $previous): ?array
    {
        // A failed statamic.com licence check reads 'unknown'. Recording it
        // would add an entry for every outage and another when it recovers.
        if (($snapshot['license_status'] ?? null) === 'unknown' && $previous !== null && array_key_exists('license_status', $previous)) {
            $snapshot['license_status'] = $previous['license_status'];
        }

        foreach (['composer', 'npm'] as $eco) {
            $vulnsFailed    = ($audit[$eco]['status'] ?? null) === 'error';
            $outdatedFailed = ! empty($audit[$eco]['outdated']['error']);

            if (! $vulnsFailed && ! $outdatedFailed) {
                continue;
            }

            if ($previous === null) {
                return null;
            }

            // Security-update counts depend on both lookups.
            $fields = ["{$eco}_security_updates"];

            if ($vulnsFailed) {
                array_push($fields, "{$eco}_vulns", "{$eco}_vuln_packages", "{$eco}_vuln_severities", "{$eco}_dependency_parents");
            }

            if ($outdatedFailed) {
                $fields[] = "{$eco}_outdated";
            }

            // An unreadable lock file also empties the installed-package
            // list, which the update report would read as "all removed".
            if (! empty($audit[$eco]['lock_unreadable'])) {
                $fields[] = "{$eco}_packages";
            }

            foreach ($fields as $field) {
                if (array_key_exists($field, $previous)) {
                    $snapshot[$field] = $previous[$field];
                } else {
                    unset($snapshot[$field]);
                }
            }
        }

        return $snapshot;
    }

    protected static function vulnPackageMap(array $byPackage): array
    {
        $map = [];
        foreach ($byPackage as $entry) {
            $name = $entry['name'] ?? null;
            if ($name === null) continue;
            $map[$name] = (int) ($entry['count'] ?? 0);
        }
        return $map;
    }

    /**
     * `[name => highest severity]` from a by_package list (each entry already
     * carries `highest`, e.g. CRITICAL/HIGH/MEDIUM/LOW/UNKNOWN). Lets the
     * maintenance report attribute a severity to each security-related update.
     */
    protected static function vulnSeverityMap(array $byPackage): array
    {
        $map = [];
        foreach ($byPackage as $entry) {
            $name = $entry['name'] ?? null;
            if ($name === null) continue;
            $map[$name] = $entry['highest'] ?? 'UNKNOWN';
        }
        return $map;
    }

    protected function matches(array $a, array $b): bool
    {
        foreach (self::TRACKED_FIELDS as $field) {
            if (($a[$field] ?? null) !== ($b[$field] ?? null)) {
                return false;
            }
        }

        foreach (self::CHANGE_MAPS as $field) {
            // Snapshots from before a map existed don't count as a change.
            if (! array_key_exists($field, $a) || ! array_key_exists($field, $b)) {
                continue;
            }

            $x = (array) $a[$field];
            $y = (array) $b[$field];
            ksort($x);
            ksort($y);

            // Strict: loose == treats versions '1.10' and '1.1' as equal.
            if ($x !== $y) {
                return false;
            }
        }

        return true;
    }

    protected function prune(array $entries): array
    {
        $cutoff = Carbon::now()->subDays(self::RETENTION_DAYS);

        $kept = array_values(array_filter($entries, function ($entry) use ($cutoff) {
            try {
                return Carbon::parse($entry['recorded_at'])->greaterThanOrEqualTo($cutoff);
            } catch (\Throwable $e) {
                return false;
            }
        }));

        // Newest first, so this keeps the most recent.
        return array_slice($kept, 0, self::MAX_ENTRIES);
    }

    /**
     * Atomically replace the history file. See AtomicFile.
     */
    protected function write(array $entries): void
    {
        AtomicFile::putJson(self::RELATIVE_PATH, $entries);
    }
}
