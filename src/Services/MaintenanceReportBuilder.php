<?php

namespace D3Creative\Sentinel\Services;

use Carbon\CarbonImmutable;

/**
 * Aggregates HistoryService snapshots into per-category maintenance activity
 * counts since a plan's start date - the data behind the client-facing
 * "Plan Summary" report.
 *
 * Every recorded change between two consecutive snapshots is one unit of work:
 * a platform version bump counts as one update; each package whose version
 * changed counts as one package update. A package update is "security-related"
 * when that package carried a known OSV advisory in the snapshot *before* the
 * bump (read from the `*_vuln_packages` map that already lives in history).
 *
 * Counts are net-change between scans (two bumps landing between the same pair
 * of scans read as one) - an undercount, never an overcount. This is a pure
 * read-side aggregation; it captures no new data.
 */
class MaintenanceReportBuilder
{
    const SEVERITIES = ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'UNKNOWN'];

    /**
     * @param array $history HistoryService::all() - newest-first snapshots.
     * @param array $plan    MaintenancePlanService::all().
     */
    public static function build(array $history, array $plan): array
    {
        $planOut = [
            'name'          => ($plan['plan_name'] ?? '') !== '' ? $plan['plan_name'] : null,
            'start'         => self::formatDate($plan['start_date'] ?? null),
            'expiry'        => self::formatDate($plan['expiry_date'] ?? null),
            'show_reminder' => (bool) ($plan['show_reminder'] ?? true),
            'reminder_days' => (int) ($plan['reminder_days'] ?? 30),
        ];

        if (empty($history)) {
            return self::emptyResult($planOut);
        }

        // Oldest -> newest working copy.
        $ordered = array_values(array_reverse($history));

        $windowStart = self::parse($plan['start_date'] ?? null)
            ?? self::parse($ordered[0]['recorded_at'] ?? null);

        if ($windowStart === null) {
            return self::emptyResult($planOut);
        }

        // Baseline = newest snapshot strictly before the window (the state
        // entering it); if none, the oldest snapshot.
        $baseline = $ordered[0];
        foreach ($ordered as $snap) {
            $at = self::parse($snap['recorded_at'] ?? null);
            if ($at !== null && $at->lessThan($windowStart)) {
                $baseline = $snap;
            } else {
                break;
            }
        }

        $platform = [
            'statamic' => ['count' => 0, 'from' => $baseline['statamic'] ?? null, 'to' => $baseline['statamic'] ?? null],
            'laravel'  => ['count' => 0, 'from' => $baseline['laravel']  ?? null, 'to' => $baseline['laravel']  ?? null],
            'php'      => ['count' => 0, 'from' => $baseline['php']      ?? null, 'to' => $baseline['php']      ?? null],
        ];

        $composer = self::freshEco();
        $npm      = self::freshEco();

        for ($i = 1; $i < count($ordered); $i++) {
            $prev  = $ordered[$i - 1];
            $cur   = $ordered[$i];
            $curAt = self::parse($cur['recorded_at'] ?? null);

            if ($curAt === null || $curAt->lessThan($windowStart)) {
                continue;
            }

            foreach (['statamic', 'laravel', 'php'] as $key) {
                if (UpdateReportBuilder::diffPlatform($prev[$key] ?? null, $cur[$key] ?? null)['changed']) {
                    $platform[$key]['count']++;
                }
            }

            self::tallyEcosystem($composer, $prev, $cur, 'composer');
            self::tallyEcosystem($npm, $prev, $cur, 'npm');
        }

        // "to" = the newest recorded version, so the row reads baseline -> latest.
        $newest = $ordered[count($ordered) - 1];
        foreach (['statamic', 'laravel', 'php'] as $key) {
            if (($newest[$key] ?? null) !== null) {
                $platform[$key]['to'] = $newest[$key];
            }
        }

        // Effective coverage start: we can't report before the earliest snapshot,
        // even if the plan started earlier (365-day retention).
        $earliest = self::parse($ordered[0]['recorded_at'] ?? null);
        $since    = ($earliest !== null && $earliest->greaterThan($windowStart)) ? $earliest : $windowStart;

        $totalUpdates = $platform['statamic']['count'] + $platform['laravel']['count'] + $platform['php']['count']
            + $composer['updates'] + $npm['updates'];

        return [
            'has_data'               => true,
            'since'                  => self::display($since),
            'to'                     => self::display(self::parse($newest['recorded_at'] ?? null) ?? $since),
            'plan'                   => $planOut,
            'platform'               => $platform,
            'composer'               => $composer,
            'npm'                    => $npm,
            'total_updates'          => $totalUpdates,
            'total_security_updates' => $composer['security_updates'] + $npm['security_updates'],
        ];
    }

    /**
     * Fold one consecutive snapshot pair's package changes into the running
     * ecosystem tally: total version bumps, how many were security-related,
     * and (where severity history exists) the severity breakdown.
     */
    protected static function tallyEcosystem(array &$eco, array $prev, array $cur, string $type): void
    {
        $updated = UpdateReportBuilder::diffPackages(
            $prev[$type . '_packages'] ?? [],
            $cur[$type . '_packages'] ?? []
        )['updated'];

        $prevVulns      = $prev[$type . '_vuln_packages']    ?? [];   // [name => count]
        $prevSeverities = $prev[$type . '_vuln_severities']  ?? null; // [name => severity] or absent (old snapshot)

        foreach ($updated as $pkg) {
            $eco['updates']++;

            $name = $pkg['name'] ?? null;
            if ($name === null || (int) ($prevVulns[$name] ?? 0) < 1) {
                continue; // not vulnerable before the bump -> not security-related
            }

            $eco['security_updates']++;

            if (is_array($prevSeverities) && ! empty($prevSeverities[$name])) {
                $severity = strtoupper((string) $prevSeverities[$name]);
                if (! isset($eco['security_by_severity'][$severity])) {
                    $severity = 'UNKNOWN';
                }
                $eco['security_by_severity'][$severity]++;
                $eco['security_updates_with_severity']++;
            }
        }
    }

    protected static function freshEco(): array
    {
        return [
            'updates'                        => 0,
            'security_updates'               => 0,
            'security_by_severity'           => array_fill_keys(self::SEVERITIES, 0),
            'security_updates_with_severity' => 0,
        ];
    }

    protected static function emptyResult(array $planOut): array
    {
        return [
            'has_data'               => false,
            'since'                  => null,
            'to'                     => null,
            'plan'                   => $planOut,
            'platform'               => [
                'statamic' => ['count' => 0, 'from' => null, 'to' => null],
                'laravel'  => ['count' => 0, 'from' => null, 'to' => null],
                'php'      => ['count' => 0, 'from' => null, 'to' => null],
            ],
            'composer'               => self::freshEco(),
            'npm'                    => self::freshEco(),
            'total_updates'          => 0,
            'total_security_updates' => 0,
        ];
    }

    protected static function parse(?string $value): ?CarbonImmutable
    {
        if (! $value) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected static function formatDate(?string $value): ?string
    {
        $date = self::parse($value);

        return $date ? $date->format('j M Y') : null;
    }

    protected static function display(CarbonImmutable $date): string
    {
        return $date->format('j M Y');
    }
}
