<?php

namespace D3Creative\Sentinel\Services;

class UpdateReportBuilder
{
    /**
     * Diff two history snapshots into a report-ready structure.
     *
     * Both arguments are entries from HistoryService::all(). `$latest` should
     * be newer than `$previous` (the typical call site passes
     * `$history[0]` and `$history[1]`).
     *
     * Returns:
     *   [
     *     'has_changes'      => bool,
     *     'from_recorded_at' => string|null,
     *     'to_recorded_at'   => string|null,
     *     'platform'         => ['statamic' => ['from','to','changed'], 'laravel' => ..., 'php' => ...],
     *     'composer'         => ['updated' => [...], 'added' => [...], 'removed' => [...]],
     *     'npm'              => same shape as composer,
     *     'vulns'            => ['composer_resolved','composer_introduced','npm_resolved','npm_introduced'],
     *   ]
     */
    public static function build(array $latest, array $previous): array
    {
        $platform = [
            'statamic' => self::diffPlatform($previous['statamic'] ?? null, $latest['statamic'] ?? null),
            'laravel'  => self::diffPlatform($previous['laravel']  ?? null, $latest['laravel']  ?? null),
            'php'      => self::diffPlatform($previous['php']      ?? null, $latest['php']      ?? null),
        ];

        $composer = self::diffPackages(
            $previous['composer_packages'] ?? [],
            $latest['composer_packages']   ?? []
        );

        $npm = self::diffPackages(
            $previous['npm_packages'] ?? [],
            $latest['npm_packages']   ?? []
        );

        $vulns = [
            'composer_resolved'   => max(0, ((int) ($previous['composer_vulns'] ?? 0)) - ((int) ($latest['composer_vulns'] ?? 0))),
            'composer_introduced' => max(0, ((int) ($latest['composer_vulns']   ?? 0)) - ((int) ($previous['composer_vulns'] ?? 0))),
            'npm_resolved'        => max(0, ((int) ($previous['npm_vulns']      ?? 0)) - ((int) ($latest['npm_vulns']      ?? 0))),
            'npm_introduced'      => max(0, ((int) ($latest['npm_vulns']        ?? 0)) - ((int) ($previous['npm_vulns']    ?? 0))),
        ];

        $hasChanges = $platform['statamic']['changed']
            || $platform['laravel']['changed']
            || $platform['php']['changed']
            || ! empty($composer['updated']) || ! empty($composer['added']) || ! empty($composer['removed'])
            || ! empty($npm['updated'])      || ! empty($npm['added'])      || ! empty($npm['removed'])
            || $vulns['composer_resolved']   || $vulns['composer_introduced']
            || $vulns['npm_resolved']        || $vulns['npm_introduced'];

        return [
            'has_changes'      => (bool) $hasChanges,
            'from_recorded_at' => $previous['recorded_at'] ?? null,
            'to_recorded_at'   => $latest['recorded_at']   ?? null,
            'platform'         => $platform,
            'composer'         => $composer,
            'npm'              => $npm,
            'vulns'            => $vulns,
        ];
    }

    protected static function diffPlatform(?string $from, ?string $to): array
    {
        return [
            'from'    => $from,
            'to'      => $to,
            'changed' => $from !== null && $to !== null && $from !== $to,
        ];
    }

    /**
     * Diff two `[name => version]` maps into updated/added/removed lists.
     */
    protected static function diffPackages(array $previous, array $latest): array
    {
        $updated = [];
        $added   = [];
        $removed = [];

        foreach ($latest as $name => $version) {
            if (! array_key_exists($name, $previous)) {
                $added[] = ['name' => $name, 'to' => $version];
            } elseif ($previous[$name] !== $version) {
                $updated[] = ['name' => $name, 'from' => $previous[$name], 'to' => $version];
            }
        }

        foreach ($previous as $name => $version) {
            if (! array_key_exists($name, $latest)) {
                $removed[] = ['name' => $name, 'from' => $version];
            }
        }

        usort($updated, fn($a, $b) => strcmp($a['name'], $b['name']));
        usort($added,   fn($a, $b) => strcmp($a['name'], $b['name']));
        usort($removed, fn($a, $b) => strcmp($a['name'], $b['name']));

        return ['updated' => $updated, 'added' => $added, 'removed' => $removed];
    }
}
