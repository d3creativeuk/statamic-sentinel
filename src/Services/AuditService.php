<?php

namespace D3Creative\Sentinel\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class AuditService
{
    const OSV_BATCH_API     = 'https://api.osv.dev/v1/querybatch';
    const PACKAGIST_STATAMIC_API = 'https://repo.packagist.org/p2/statamic/cms.json';
    const PACKAGIST_LARAVEL_API  = 'https://repo.packagist.org/p2/laravel/framework.json';
    const CACHE_KEY = 'd3creative_sentinel_audit';

    const EOL_DATE_PHP_API = 'https://endoflife.date/api/php.json';

    /**
     * Last cached audit result, or null if nothing is cached yet.
     * Never triggers a scan.
     */
    public function cached(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    /**
     * Return cached result; if nothing is cached, run a scan and cache it.
     */
    public function run(): array
    {
        return $this->cached() ?? $this->refresh();
    }

    // -------------------------------------------------------------------------
    // Laravel
    // -------------------------------------------------------------------------

    protected function laravelInfo(array $composerAudit = []): array
    {
        $current = app()->version();
        $major   = (int) explode('.', $current)[0];

        // Laravel releases one major version per year in approximately February,
        // starting with Laravel 9 in February 2022.
        // Active support lasts 18 months; security fixes last 24 months.
        // By extrapolating the release year from the major version number, this
        // calculation handles future versions without any code changes.
        //
        // Older versions (< 9) had irregular schedules, so we hard-code those.
        $legacyEol = [6 => '2022-09-06', 7 => '2021-03-03', 8 => '2023-01-24'];

        if (isset($legacyEol[$major])) {
            $eolDate = \Carbon\Carbon::parse($legacyEol[$major]);
            $status  = now()->lt($eolDate) ? 'security' : 'eol';
        } else {
            // Approximate release date: 1 February of the corresponding year.
            // Laravel 9 → 2022, Laravel 10 → 2023, Laravel 11 → 2024, etc.
            $releaseYear  = 2022 + ($major - 9);
            $releaseDate  = \Carbon\Carbon::create($releaseYear, 2, 1);
            $activeEnds   = $releaseDate->copy()->addMonths(18);
            $securityEnds = $releaseDate->copy()->addMonths(24);

            if (now()->lt($activeEnds)) {
                $status = 'active';
            } elseif (now()->lt($securityEnds)) {
                $status = 'security';
            } else {
                $status = 'eol';
            }
        }

        $labels = [
            'active'   => 'Active Support',
            'security' => 'Security Fixes Only',
            'eol'      => 'End of Life',
        ];

        $latest    = $this->fetchLatestLaravelVersion();
        $isLatest  = $latest && version_compare($current, $latest, '>=');

        return [
            'version'                   => $current,
            'latest'                    => $latest,
            'is_latest'                 => $isLatest,
            'status'                    => $status,
            'label'                     => $labels[$status],
            'security_update_available' => ! $isLatest && $this->hasSecurityUpdateFor('laravel/framework', $composerAudit),
        ];
    }

    protected function fetchLatestLaravelVersion(): ?string
    {
        try {
            $response = Http::timeout(5)->get(self::PACKAGIST_LARAVEL_API);

            if (! $response->ok()) return null;

            foreach ($response->json('packages.laravel/framework', []) as $version) {
                $v = ltrim($version['version'] ?? '', 'v');
                if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $v)) {
                    return $v;
                }
            }
        } catch (\Throwable $e) {
            // Silently fail
        }

        return null;
    }

    /**
     * Run a scan and overwrite the cache. Used by the scheduler, the
     * sentinel:scan command, and the manual "Scan now" / "Refresh" buttons.
     * Cached forever — the scheduler owns freshness.
     */
    public function refresh(): array
    {
        $composer = $this->annotateOutdatedSecurity(
            array_merge($this->composerAudit(), [
                'outdated'  => $this->composerOutdated(),
                'installed' => $this->composerInstalledDirect(),
            ])
        );

        $npm = $this->annotateOutdatedSecurity(
            array_merge($this->npmAudit(), [
                'outdated'  => $this->npmOutdated(),
                'installed' => $this->npmInstalledDirect(),
            ])
        );

        $result = [
            'statamic'   => $this->statamicInfo($composer),
            'laravel'    => $this->laravelInfo($composer),
            'php'        => $this->phpInfo(),
            'composer'   => $composer,
            'npm'        => $npm,
            'audited_at' => now()->format('j M Y, H:i'),
        ];

        Cache::forever(self::CACHE_KEY, $result);

        app(HistoryService::class)->recordIfChanged($result);

        return $result;
    }

    /**
     * For each package in the ecosystem's `outdated.packages`, set a
     * `security_update` flag indicating whether updating it would resolve a
     * known OSV advisory. Adds `outdated.security_updates_total` alongside.
     */
    protected function annotateOutdatedSecurity(array $ecosystem): array
    {
        $packages = $ecosystem['outdated']['packages'] ?? [];
        $count    = 0;

        foreach ($packages as $i => $pkg) {
            $isSecurity = $this->hasSecurityUpdateFor($pkg['name'] ?? '', $ecosystem);
            $packages[$i]['security_update'] = $isSecurity;
            if ($isSecurity) {
                $count++;
            }
        }

        $ecosystem['outdated']['packages']                = $packages;
        $ecosystem['outdated']['security_updates_total']  = $count;

        return $ecosystem;
    }

    // -------------------------------------------------------------------------
    // Statamic
    // -------------------------------------------------------------------------

    protected function statamicInfo(array $composerAudit = []): array
    {
        $current = \Statamic\Statamic::version();
        $latest  = $this->fetchLatestStatamicVersion();

        $isLatest = $latest && version_compare($current, $latest, '>=');

        return [
            'current'                   => $current,
            'latest'                    => $latest,
            'is_latest'                 => $isLatest,
            'status'                    => $isLatest ? 'ok' : ($latest ? 'outdated' : 'unknown'),
            'security_update_available' => ! $isLatest && $this->hasSecurityUpdateFor('statamic/cms', $composerAudit),
        ];
    }

    /**
     * Does the given package have at least one OSV advisory with a fix available?
     * OSV only catalogs security advisories, so any hit here is by definition
     * a security update — never a cosmetic/bug-fix upgrade.
     */
    protected function hasSecurityUpdateFor(string $packageName, array $composerAudit): bool
    {
        foreach ($composerAudit['severities'] ?? [] as $severity) {
            foreach ($severity['vulns'] ?? [] as $vuln) {
                if (($vuln['package'] ?? null) === $packageName && ! empty($vuln['fix_available'])) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function fetchLatestStatamicVersion(): ?string
    {
        try {
            $response = Http::timeout(5)->get(self::PACKAGIST_STATAMIC_API);

            if (! $response->ok()) {
                return null;
            }

            $packages = $response->json('packages.statamic/cms', []);

            // Packagist p2 API returns versions newest-first
            foreach ($packages as $version) {
                $v = ltrim($version['version'] ?? '', 'v');
                // Skip dev / RC / beta releases
                if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $v)) {
                    return $v;
                }
            }
        } catch (\Throwable $e) {
            // Silently fail
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // PHP
    // -------------------------------------------------------------------------

    protected function phpInfo(): array
    {
        $full       = PHP_VERSION;
        $majorMinor = implode('.', array_slice(explode('.', $full), 0, 2));

        try {
            $response = Http::timeout(5)->get(self::EOL_DATE_PHP_API);

            if ($response->ok()) {
                $branches = collect($response->json());

                $branch = $branches->firstWhere('cycle', $majorMinor);

                if ($branch) {
                    $today        = now();
                    $activeEnds   = \Carbon\Carbon::parse($branch['support']);
                    $securityEnds = \Carbon\Carbon::parse($branch['eol']);
                    $latest       = $branch['latest'] ?? null;

                    if ($today->gt($securityEnds)) {
                        $status = 'eol';
                        $label  = 'End of Life';
                    } elseif ($today->gt($activeEnds)) {
                        $status = 'security';
                        $label  = 'Security Fixes Only';
                    } else {
                        $status = 'active';
                        $label  = 'Active Support';
                    }

                    return [
                        'version'   => $full,
                        'latest'    => $latest,
                        'is_latest' => $latest && version_compare($full, $latest, '>='),
                        'status'    => $status,
                        'label'     => $label,
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Fall through to unknown
        }

        return [
            'version'   => $full,
            'latest'    => null,
            'is_latest' => null,
            'status'    => 'unknown',
            'label'     => 'Unknown',
        ];
    }

    // -------------------------------------------------------------------------
    // Composer audit via OSV
    // -------------------------------------------------------------------------

    protected function composerAudit(): array
    {
        $lockPath = base_path('composer.lock');

        if (! file_exists($lockPath)) {
            return ['status' => 'unavailable', 'message' => 'composer.lock not found.', 'severities' => [], 'counts' => [], 'total_packages' => 0, 'total_vulns' => 0];
        }

        $lock     = json_decode(file_get_contents($lockPath), true);
        $packages = array_merge(
            $lock['packages']         ?? [],
            $lock['packages-dev']     ?? []
        );

        if (empty($packages)) {
            return ['status' => 'ok', 'message' => 'No packages found.', 'severities' => [], 'counts' => [], 'total_packages' => 0, 'total_vulns' => 0];
        }

        $queries = array_map(fn($p) => [
            'package' => ['name' => $p['name'], 'ecosystem' => 'Packagist'],
            'version' => ltrim($p['version'], 'v'),
        ], $packages);

        return $this->queryOsv($queries, count($packages));
    }

    // -------------------------------------------------------------------------
    // npm audit via OSV
    // -------------------------------------------------------------------------

    protected function npmAudit(): array
    {
        $lockPath = base_path('package-lock.json');

        if (! file_exists($lockPath)) {
            return ['status' => 'unavailable', 'message' => 'package-lock.json not found.', 'severities' => [], 'counts' => [], 'total_packages' => 0, 'total_vulns' => 0];
        }

        $lock = json_decode(file_get_contents($lockPath), true);

        // Support both lockfile v1 (dependencies) and v2/v3 (packages)
        $packages = [];

        if (! empty($lock['packages'])) {
            foreach ($lock['packages'] as $path => $data) {
                if ($path === '' || empty($data['version'])) continue; // skip root
                $name = preg_replace('#^node_modules/#', '', $path);
                $packages[$name] = $data['version'];
            }
        } elseif (! empty($lock['dependencies'])) {
            foreach ($lock['dependencies'] as $name => $data) {
                $packages[$name] = ltrim($data['version'] ?? '', 'v^~');
            }
        }

        if (empty($packages)) {
            return ['status' => 'ok', 'message' => 'No packages found.', 'severities' => [], 'counts' => [], 'total_packages' => 0, 'total_vulns' => 0];
        }

        $queries = array_map(fn($name, $version) => [
            'package' => ['name' => $name, 'ecosystem' => 'npm'],
            'version' => $version,
        ], array_keys($packages), array_values($packages));

        return $this->queryOsv(array_values($queries), count($packages));
    }

    // -------------------------------------------------------------------------
    // Shared OSV query
    // -------------------------------------------------------------------------

    protected function queryOsv(array $queries, int $totalPackages): array
    {
        $severities = [
            'CRITICAL' => ['count' => 0, 'packages' => [], 'vulns' => []],
            'HIGH'     => ['count' => 0, 'packages' => [], 'vulns' => []],
            'MEDIUM'   => ['count' => 0, 'packages' => [], 'vulns' => []],
            'LOW'      => ['count' => 0, 'packages' => [], 'vulns' => []],
            'UNKNOWN'  => ['count' => 0, 'packages' => [], 'vulns' => []],
        ];

        // querybatch returns only vuln IDs + modified timestamps (no severity,
        // summary, or affected ranges). Collect pairs here, then hydrate below.
        $pairs = [];

        foreach (array_chunk($queries, 500) as $chunk) {
            try {
                $response = Http::timeout(10)->post(self::OSV_BATCH_API, ['queries' => $chunk]);

                if (! $response->ok()) continue;

                foreach ($response->json('results', []) as $index => $result) {
                    if (empty($result['vulns'])) continue;

                    $pkg = $chunk[$index]['package']['name'];

                    foreach ($result['vulns'] as $vuln) {
                        if (! empty($vuln['id'])) {
                            $pairs[] = ['package' => $pkg, 'id' => $vuln['id']];
                        }
                    }
                }
            } catch (\Throwable $e) {
                return [
                    'status'         => 'error',
                    'message'        => 'Could not reach vulnerability database.',
                    'severities'     => $severities,
                    'counts'         => array_map(fn($s) => $s['count'], $severities),
                    'total_packages' => $totalPackages,
                    'total_vulns'    => 0,
                ];
            }
        }

        if (empty($pairs)) {
            return [
                'status'         => 'ok',
                'severities'     => $severities,
                'counts'         => array_map(fn($s) => $s['count'], $severities),
                'total_packages' => $totalPackages,
                'total_vulns'    => 0,
            ];
        }

        $uniqueIds = array_values(array_unique(array_column($pairs, 'id')));
        $details   = $this->fetchVulnDetails($uniqueIds);

        foreach ($pairs as $pair) {
            $vuln     = $details[$pair['id']] ?? ['id' => $pair['id']];
            $severity = $this->extractSeverity($vuln);

            $fixAvailable = false;
            foreach ($vuln['affected'] ?? [] as $affected) {
                foreach ($affected['ranges'] ?? [] as $range) {
                    foreach ($range['events'] ?? [] as $event) {
                        if (isset($event['fixed'])) {
                            $fixAvailable = true;
                            break 3;
                        }
                    }
                }
            }

            $severities[$severity]['count']++;

            if (! in_array($pair['package'], $severities[$severity]['packages'])) {
                $severities[$severity]['packages'][] = $pair['package'];
            }

            $severities[$severity]['vulns'][] = [
                'id'            => $pair['id'],
                'package'       => $pair['package'],
                'summary'       => $vuln['summary'] ?? 'No description available.',
                'fix_available' => $fixAvailable,
                'url'           => 'https://osv.dev/vulnerability/' . $pair['id'],
            ];
        }

        $totalVulns = array_sum(array_column($severities, 'count'));

        return [
            'status'         => $totalVulns > 0 ? 'vulnerable' : 'ok',
            'severities'     => $severities,
            'counts'         => array_map(fn($s) => $s['count'], $severities),
            'total_packages' => $totalPackages,
            'total_vulns'    => $totalVulns,
        ];
    }

    /**
     * Fetch full vulnerability details for the given IDs, concurrently.
     * Failed lookups are silently omitted — those vulns fall back to UNKNOWN.
     */
    protected function fetchVulnDetails(array $ids): array
    {
        $details = [];

        foreach (array_chunk($ids, 50) as $chunk) {
            try {
                $responses = Http::pool(function ($pool) use ($chunk) {
                    return array_map(
                        fn($id) => $pool->timeout(10)->get('https://api.osv.dev/v1/vulns/' . $id),
                        $chunk
                    );
                });
            } catch (\Throwable $e) {
                continue;
            }

            foreach ($chunk as $i => $id) {
                $response = $responses[$i] ?? null;
                if (! $response || ! $response->ok()) continue;

                $details[$id] = $response->json() ?? [];
            }
        }

        return $details;
    }

    protected function extractSeverity(array $vuln): string
    {
        // GitHub advisories use database_specific.severity with values like MODERATE
        $dbSeverity = strtoupper($vuln['database_specific']['severity'] ?? '');
        $map = ['CRITICAL' => 'CRITICAL', 'HIGH' => 'HIGH', 'MODERATE' => 'MEDIUM', 'MEDIUM' => 'MEDIUM', 'LOW' => 'LOW'];
        if (isset($map[$dbSeverity])) {
            return $map[$dbSeverity];
        }

        // Try CVSS numeric score from severity array
        foreach ($vuln['severity'] ?? [] as $s) {
            $score = $s['score'] ?? '';
            // Plain numeric score
            if (is_numeric($score)) {
                return $this->cvssScoreToSeverity((float) $score);
            }
            // CVSS vector string — extract base score via regex
            if (preg_match('/\/(\d+\.\d+)$/', $score, $m)) {
                return $this->cvssScoreToSeverity((float) $m[1]);
            }
        }

        return 'UNKNOWN';
    }

    protected function cvssScoreToSeverity(float $score): string
    {
        if ($score >= 9.0) return 'CRITICAL';
        if ($score >= 7.0) return 'HIGH';
        if ($score >= 4.0) return 'MEDIUM';
        return 'LOW';
    }

    // -------------------------------------------------------------------------
    // Outdated package checks (direct dependencies only)
    // -------------------------------------------------------------------------

    /**
     * Direct composer dependencies and their installed versions:
     * `['vendor/pkg' => '1.2.3', ...]`. Empty array on any read failure.
     * Used both by composerOutdated() and by HistoryService for diffing.
     */
    public function composerInstalledDirect(): array
    {
        $manifestPath = base_path('composer.json');
        $lockPath     = base_path('composer.lock');

        if (! file_exists($manifestPath) || ! file_exists($lockPath)) {
            return [];
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $lock     = json_decode(file_get_contents($lockPath), true);

        $direct = array_keys(array_merge(
            $manifest['require']     ?? [],
            $manifest['require-dev'] ?? []
        ));

        $direct = array_values(array_filter($direct, fn($n) =>
            str_contains($n, '/') && ! str_starts_with($n, 'ext-')
        ));

        if (empty($direct)) return [];

        $installed = [];
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $pkg) {
            $installed[$pkg['name']] = ltrim($pkg['version'] ?? '', 'v');
        }

        $result = [];
        foreach ($direct as $name) {
            if (isset($installed[$name])) {
                $result[$name] = $installed[$name];
            }
        }

        return $result;
    }

    protected function composerOutdated(): array
    {
        $installed = $this->composerInstalledDirect();

        if (empty($installed)) return ['total' => 0, 'packages' => []];

        $toCheck = array_keys($installed);

        // Fetch latest versions from Packagist concurrently
        try {
            $responses = Http::pool(function ($pool) use ($toCheck) {
                return array_map(
                    fn($name) => $pool->timeout(5)->get("https://repo.packagist.org/p2/{$name}.json"),
                    $toCheck
                );
            });
        } catch (\Throwable $e) {
            return ['total' => 0, 'packages' => [], 'error' => true];
        }

        $outdated = [];

        foreach ($toCheck as $i => $name) {
            $response = $responses[$i] ?? null;
            if (! $response || ! $response->ok()) continue;

            $latest = null;
            foreach ($response->json("packages.{$name}", []) as $v) {
                $ver = ltrim($v['version'] ?? '', 'v');
                if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $ver)) {
                    $latest = $ver;
                    break;
                }
            }

            if (! $latest) continue;

            $current = $installed[$name];
            if (version_compare($current, $latest, '<')) {
                $outdated[] = ['name' => $name, 'current' => $current, 'latest' => $latest];
            }
        }

        return ['total' => count($outdated), 'packages' => $outdated];
    }

    /**
     * Direct npm dependencies and their installed versions:
     * `['alpinejs' => '3.13.0', ...]`. Empty array on any read failure.
     * Supports lock v1 (dependencies) and v2/v3 (packages).
     */
    public function npmInstalledDirect(): array
    {
        $manifestPath = base_path('package.json');
        $lockPath     = base_path('package-lock.json');

        if (! file_exists($manifestPath) || ! file_exists($lockPath)) {
            return [];
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $lock     = json_decode(file_get_contents($lockPath), true);

        $direct = array_keys(array_merge(
            $manifest['dependencies']    ?? [],
            $manifest['devDependencies'] ?? []
        ));

        if (empty($direct)) return [];

        $installed = [];
        if (! empty($lock['packages'])) {
            foreach ($lock['packages'] as $path => $data) {
                if ($path === '' || empty($data['version'])) continue;
                $name = preg_replace('#^node_modules/#', '', $path);
                $installed[$name] = $data['version'];
            }
        } elseif (! empty($lock['dependencies'])) {
            foreach ($lock['dependencies'] as $name => $data) {
                $installed[$name] = ltrim($data['version'] ?? '', 'v^~');
            }
        }

        $result = [];
        foreach ($direct as $name) {
            if (isset($installed[$name])) {
                $result[$name] = $installed[$name];
            }
        }

        return $result;
    }

    protected function npmOutdated(): array
    {
        $installed = $this->npmInstalledDirect();

        if (empty($installed)) return ['total' => 0, 'packages' => []];

        $toCheck = array_keys($installed);

        // Fetch latest versions from npm registry concurrently
        // Scoped packages (@scope/name) are supported natively by the registry URL
        try {
            $responses = Http::pool(function ($pool) use ($toCheck) {
                return array_map(
                    fn($name) => $pool->timeout(5)->get("https://registry.npmjs.org/{$name}/latest"),
                    $toCheck
                );
            });
        } catch (\Throwable $e) {
            return ['total' => 0, 'packages' => [], 'error' => true];
        }

        $outdated = [];

        foreach ($toCheck as $i => $name) {
            $response = $responses[$i] ?? null;
            if (! $response || ! $response->ok()) continue;

            $latest  = $response->json('version');
            if (! $latest) continue;

            $current = ltrim($installed[$name], 'v^~');
            if (version_compare($current, $latest, '<')) {
                $outdated[] = ['name' => $name, 'current' => $current, 'latest' => $latest];
            }
        }

        return ['total' => count($outdated), 'packages' => $outdated];
    }
}
