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
     *     'vulns'            => [
     *         'composer_resolved','composer_introduced','composer_open',
     *         'npm_resolved','npm_introduced','npm_open',
     *         'composer_resolved_packages','composer_introduced_packages','composer_open_packages',
     *         'npm_resolved_packages','npm_introduced_packages','npm_open_packages',
     *     ],  // package lists: see diffVulnPackages()
     *   ]
     */
    public static function build(array $latest, array $previous): array
    {
        $platform = [
            'statamic' => self::diffPlatform($previous['statamic'] ?? null, $latest['statamic'] ?? null),
            'laravel'  => self::diffPlatform($previous['laravel']  ?? null, $latest['laravel']  ?? null),
            'php'      => self::diffPlatform($previous['php']      ?? null, $latest['php']      ?? null),
        ];

        // Statamic licence status (ok/renewal/invalid/...) between the two
        // snapshots. Reuses diffPlatform's from/to/changed shape. Null on
        // installs without licensing - the email skips the row in that case.
        $license = self::diffPlatform($previous['license_status'] ?? null, $latest['license_status'] ?? null);

        $composer = self::diffPackages(
            $previous['composer_packages'] ?? [],
            $latest['composer_packages']   ?? []
        );

        $npm = self::diffPackages(
            $previous['npm_packages'] ?? [],
            $latest['npm_packages']   ?? []
        );

        $composerVulnDiff = self::diffVulnPackages('composer', $previous, $latest);
        $npmVulnDiff      = self::diffVulnPackages('npm', $previous, $latest);

        $composer = self::markSecurityUpdates($composer, $composerVulnDiff['resolved']);
        $npm      = self::markSecurityUpdates($npm, $npmVulnDiff['resolved']);

        $vulns = [
            'composer_resolved'   => $composerVulnDiff['resolved_count'],
            'composer_introduced' => $composerVulnDiff['introduced_count'],
            'composer_open'       => $composerVulnDiff['open_count'],
            'npm_resolved'        => $npmVulnDiff['resolved_count'],
            'npm_introduced'      => $npmVulnDiff['introduced_count'],
            'npm_open'            => $npmVulnDiff['open_count'],
            'composer_resolved_packages'   => $composerVulnDiff['resolved'],
            'composer_introduced_packages' => $composerVulnDiff['introduced'],
            'composer_open_packages'       => $composerVulnDiff['open'],
            'npm_resolved_packages'        => $npmVulnDiff['resolved'],
            'npm_introduced_packages'      => $npmVulnDiff['introduced'],
            'npm_open_packages'            => $npmVulnDiff['open'],
        ];

        $hasChanges = $platform['statamic']['changed']
            || $platform['laravel']['changed']
            || $platform['php']['changed']
            || $license['changed']
            || ! empty($composer['updated']) || ! empty($composer['added']) || ! empty($composer['removed'])
            || ! empty($npm['updated'])      || ! empty($npm['added'])      || ! empty($npm['removed'])
            || $vulns['composer_resolved']   || $vulns['composer_introduced']
            || $vulns['npm_resolved']        || $vulns['npm_introduced'];

        return [
            'has_changes'      => (bool) $hasChanges,
            'from_recorded_at' => $previous['recorded_at'] ?? null,
            'to_recorded_at'   => $latest['recorded_at']   ?? null,
            'platform'         => $platform,
            'license'          => $license,
            'composer'         => $composer,
            'npm'              => $npm,
            'vulns'            => $vulns,
        ];
    }

    public static function diffPlatform(?string $from, ?string $to): array
    {
        return [
            'from'    => $from,
            'to'      => $to,
            'changed' => $from !== null && $to !== null && $from !== $to,
        ];
    }

    /**
     * Flag the package changes that fixed vulnerabilities (`security`), and
     * add the fixed packages the lists don't have: the lists only cover direct
     * dependencies, and most fixes land in indirect ones. A client sees
     * "guzzlehttp/guzzle (security update)" in the package list rather than
     * the same package again in a separate section, and needn't know which
     * dependency pulled it in.
     *
     * A fixed package whose version didn't change (an advisory withdrawn or
     * narrowed) isn't an update, so it's left out.
     */
    protected static function markSecurityUpdates(array $lists, array $resolved): array
    {
        foreach ($resolved as $fix) {
            $name = $fix['name'];

            foreach (['updated', 'removed', 'added'] as $list) {
                foreach ($lists[$list] as $i => $row) {
                    if ($row['name'] === $name) {
                        $lists[$list][$i]['security'] = true;

                        continue 3;
                    }
                }
            }

            $from = $fix['from'] ?? null;
            $to   = $fix['to'] ?? null;

            if (! empty($fix['removed'])) {
                $lists['removed'][] = ['name' => $name, 'from' => $from, 'security' => true];
            } elseif ($from === null || $to === null || $from !== $to) {
                $lists['updated'][] = ['name' => $name, 'from' => $from, 'to' => $to, 'security' => true];
            }
        }

        foreach (['updated', 'removed'] as $list) {
            usort($lists[$list], fn ($a, $b) => strcmp($a['name'], $b['name']));
        }

        return $lists;
    }

    /**
     * Diff two `[name => version]` maps into updated/added/removed lists.
     */
    public static function diffPackages(array $previous, array $latest): array
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

    /**
     * Diff one ecosystem's `[name => count]` vuln maps into per-package
     * resolved/introduced/open lists of
     * `['name' => string, 'count' => int, 'parent' => ?string, 'ecosystem' => string]`,
     * sorted by name, plus the counts the email headline shows.
     *
     * `open` is what carried over unchanged from the previous snapshot. It
     * isn't a change, but the report lists it so a client sees what's still
     * outstanding (and any note explaining why), not only what moved.
     *
     * `parent` is the direct dependency that pulls a transitive package in
     * (null for a direct dependency, or a snapshot recorded before parents
     * were stored). A resolved package has dropped out of the latest map, so
     * its parent comes from the previous snapshot.
     *
     * The counts are the sum of the per-package changes, so they always match
     * the names listed. Netting the ecosystem totals instead let a fix and a
     * new issue cancel out: one-for-one read as "no changes". Snapshots too
     * old to carry per-package maps fall back to the net totals.
     */
    protected static function diffVulnPackages(string $eco, array $previous, array $latest): array
    {
        $before  = $previous["{$eco}_vuln_packages"] ?? null;
        $after   = $latest["{$eco}_vuln_packages"]   ?? null;
        $parents = [
            'previous' => $previous["{$eco}_dependency_parents"] ?? [],
            'latest'   => $latest["{$eco}_dependency_parents"]   ?? [],
        ];

        $resolved   = [];
        $introduced = [];
        $open       = [];

        $names = array_unique(array_merge(array_keys($before ?? []), array_keys($after ?? [])));

        foreach ($names as $name) {
            $was = (int) ($before[$name] ?? 0);
            $now = (int) ($after[$name]  ?? 0);

            if ($was > $now) {
                $resolved[] = self::vulnEntry($eco, $name, $was - $now, $parents['previous'])
                    + self::resolvedVersions($eco, $name, $previous, $latest);
            } elseif ($now > $was) {
                $introduced[] = self::vulnEntry($eco, $name, $now - $was, $parents['latest']);
            }

            if (min($was, $now) > 0) {
                $open[] = self::vulnEntry($eco, $name, min($was, $now), $parents['latest']);
            }
        }

        usort($resolved,   fn($a, $b) => strcmp($a['name'], $b['name']));
        usort($introduced, fn($a, $b) => strcmp($a['name'], $b['name']));
        usort($open,       fn($a, $b) => strcmp($a['name'], $b['name']));

        if (is_array($before) && is_array($after)) {
            $resolvedCount   = array_sum(array_column($resolved, 'count'));
            $introducedCount = array_sum(array_column($introduced, 'count'));
        } else {
            $delta           = ((int) ($latest["{$eco}_vulns"] ?? 0)) - ((int) ($previous["{$eco}_vulns"] ?? 0));
            $resolvedCount   = max(0, -$delta);
            $introducedCount = max(0, $delta);
        }

        return [
            'resolved'         => $resolved,
            'introduced'       => $introduced,
            'open'             => $open,
            'resolved_count'   => $resolvedCount,
            'introduced_count' => $introducedCount,
            'open_count'       => array_sum(array_column($open, 'count')),
        ];
    }

    /**
     * The version a resolved package moved from and to, from the snapshots'
     * `{eco}_vuln_versions` (null where a snapshot doesn't know it). `removed`
     * only when the latest snapshot looked the package up and found it gone
     * (stored as null); a package it never looked up isn't claimed. An npm
     * package installed at several versions reads as its lowest before and
     * highest after ("2.3.2 → 3.0.3"), not every version.
     *
     * A direct dependency falls back to the snapshot's `{eco}_packages`, which
     * every snapshot has, so it shows its old version even against one
     * recorded before vuln versions were kept.
     */
    protected static function resolvedVersions(string $eco, string $name, array $previous, array $latest): array
    {
        $before = $previous["{$eco}_vuln_versions"] ?? null;
        $after  = $latest["{$eco}_vuln_versions"]   ?? null;

        $version = function (?array $vulnVersions, array $snapshot) use ($eco, $name) {
            $fromVulns = is_array($vulnVersions) ? ($vulnVersions[$name] ?? null) : null;

            return is_string($fromVulns) && $fromVulns !== ''
                ? $fromVulns
                : (($snapshot["{$eco}_packages"][$name] ?? null) ?: null);
        };

        $pick = function ($list, bool $highest) {
            if (! is_string($list) || $list === '') {
                return null;
            }

            $versions = array_map('trim', explode(',', $list));
            usort($versions, 'version_compare');

            return $highest ? end($versions) : $versions[0];
        };

        return [
            'from'    => $pick($version($before, $previous), false),
            'to'      => $pick($version($after, $latest), true),
            'removed' => is_array($after) && array_key_exists($name, $after) && $after[$name] === null
                && ! isset($latest["{$eco}_packages"][$name]),
        ];
    }

    protected static function vulnEntry(string $eco, string $name, int $count, array $parents): array
    {
        $parent = $parents[$name] ?? null;

        return [
            'name'      => $name,
            'count'     => $count,
            'parent'    => is_string($parent) && $parent !== $name ? $parent : null,
            'ecosystem' => $eco,
        ];
    }
}
