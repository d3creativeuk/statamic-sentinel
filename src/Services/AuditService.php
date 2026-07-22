<?php

namespace D3Creative\Sentinel\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class AuditService
{
    const OSV_BATCH_API     = 'https://api.osv.dev/v1/querybatch';
    const PACKAGIST_STATAMIC_API = 'https://repo.packagist.org/p2/statamic/cms.json';
    const PACKAGIST_LARAVEL_API  = 'https://repo.packagist.org/p2/laravel/framework.json';
    const CACHE_KEY = 'd3creative_sentinel_audit';

    // Disk mirror of the cache so the last scan survives `cache:clear`
    // (which Statamic / Laravel sites routinely run after `composer update`).
    const DISK_PATH     = 'statamic-sentinel/audit.json';
    const DISK_TMP_PATH = 'statamic-sentinel/audit.json.tmp';

    const EOL_DATE_PHP_API = 'https://endoflife.date/api/php.json';

    /**
     * Per-instance lockfile cache. Each scan reads composer.lock /
     * package-lock.json from several methods (audit + installed-direct +
     * outdated); decoding once and reusing keeps a multi-MB JSON parse from
     * running 3x per refresh.
     */
    protected array $lockfileCache = [];

    /**
     * Read + decode a JSON file once per service instance. Returns null if
     * the file is missing or malformed. Internal use only.
     */
    protected function readJsonFile(string $absolutePath): ?array
    {
        if (array_key_exists($absolutePath, $this->lockfileCache)) {
            return $this->lockfileCache[$absolutePath];
        }

        if (! file_exists($absolutePath)) {
            return $this->lockfileCache[$absolutePath] = null;
        }

        $decoded = json_decode((string) file_get_contents($absolutePath), true);

        return $this->lockfileCache[$absolutePath] = is_array($decoded) ? $decoded : null;
    }

    /**
     * Last cached audit result, or null if nothing is cached yet.
     * Never triggers a scan.
     *
     * Falls back to the disk mirror if the cache has been cleared - common
     * after `composer update` since most sites run `cache:clear` afterwards.
     * On a disk hit we rehydrate the cache so subsequent reads stay hot.
     *
     * The cached snapshot is reconciled against live platform + lockfile
     * versions before being returned (see reconcileAgainstLive), so a
     * `composer update` that lands between scheduled scans doesn't leave a
     * red "security update available" pill for a release the user already
     * installed.
     */
    public function cached(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if ($cached === null) {
            $cached = $this->readFromDisk();

            if ($cached !== null) {
                Cache::forever(self::CACHE_KEY, $cached);
            }
        }

        return is_array($cached) ? $this->reconcileAgainstLive($cached) : null;
    }

    protected function readFromDisk(): ?array
    {
        try {
            $disk = Storage::disk('local');

            if (! $disk->exists(self::DISK_PATH)) {
                return null;
            }

            $decoded = json_decode((string) $disk->get(self::DISK_PATH), true);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Atomically write the audit to disk. Mirrors HistoryService::write() -
     * tmp + rename so readers never see a half-written file. Silent on
     * failure: the cache write has already succeeded by the time we get here.
     */
    protected function writeToDisk(array $result): void
    {
        try {
            $disk = Storage::disk('local');
            $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            $disk->put(self::DISK_TMP_PATH, $json);

            if (! $disk->move(self::DISK_TMP_PATH, self::DISK_PATH)) {
                $disk->delete(self::DISK_PATH);
                $disk->move(self::DISK_TMP_PATH, self::DISK_PATH);
            }
        } catch (\Throwable $e) {
            // Silent fail
        }
    }

    /**
     * Return cached result; if nothing is cached, run a scan and cache it.
     */
    public function run(): array
    {
        return $this->cached() ?? $this->refresh();
    }

    // -------------------------------------------------------------------------
    // Live-state reconciliation
    //
    // The cached snapshot is captured at scan time. When the user later runs
    // `composer update`, the snapshot's platform versions and outdated lists
    // go stale - the widget would render a red "security update available"
    // pill for a release they already installed. Reconcile against live
    // platform versions + live composer.lock / package-lock.json on every
    // read so the UI stays honest between scheduled scans. No HTTP - this
    // path must stay cheap enough to run on every dashboard render.
    //
    // OSV vulnerability lists are NOT reconciled - validating advisory
    // ranges needs more data than we cache. Most security updates also
    // appear in outdated.packages (which IS pruned), so the Security Issues
    // count stays in sync with what the user has actually installed.
    // -------------------------------------------------------------------------

    protected function reconcileAgainstLive(array $audit): array
    {
        if (! empty($audit['statamic']) && class_exists(\Statamic\Statamic::class)) {
            $audit['statamic'] = $this->reconcileStatamicAgainstLive(
                $audit['statamic'],
                \Statamic\Statamic::version()
            );
        }

        if (! empty($audit['laravel'])) {
            $audit['laravel'] = $this->reconcileLaravelAgainstLive(
                $audit['laravel'],
                app()->version()
            );
        }

        if (! empty($audit['php'])) {
            $audit['php'] = $this->reconcilePhpAgainstLive(
                $audit['php'],
                PHP_VERSION
            );
        }

        if (! empty($audit['composer']['outdated']['packages'])) {
            $audit['composer'] = $this->pruneOutdatedAgainstLive(
                $audit['composer'],
                $this->liveComposerVersions()
            );
        }

        if (! empty($audit['npm']['outdated']['packages'])) {
            $audit['npm'] = $this->pruneOutdatedAgainstLive(
                $audit['npm'],
                $this->liveNpmVersions()
            );
        }

        return $audit;
    }

    protected function reconcileStatamicAgainstLive(array $info, string $live): array
    {
        if (($info['current'] ?? null) === $live) {
            return $info;
        }

        $latest   = $info['latest'] ?? null;
        $isLatest = $latest && version_compare($live, $latest, '>=');

        $info['current']   = $live;
        $info['is_latest'] = $isLatest;
        $info['status']    = $isLatest ? 'ok' : ($latest ? 'outdated' : 'unknown');

        if ($isLatest) {
            $info['security_update_available'] = false;
            $info['security_source']           = null;
            $info['releases_behind']           = 0;
        }

        return $info;
    }

    protected function reconcileLaravelAgainstLive(array $info, string $live): array
    {
        if (($info['version'] ?? null) === $live) {
            return $info;
        }

        $latest   = $info['latest'] ?? null;
        $isLatest = $latest && version_compare($live, $latest, '>=');

        $info['version']   = $live;
        $info['is_latest'] = $isLatest;

        if ($isLatest) {
            $info['security_update_available'] = false;
            $info['releases_behind']           = 0;
        }

        return $info;
    }

    // Status + label for PHP come from endoflife.date branch lifecycle, not
    // version comparison, so they stay as captured. Only the version pill
    // (current + is_latest) needs reconciling here.
    protected function reconcilePhpAgainstLive(array $info, string $live): array
    {
        if (($info['version'] ?? null) === $live) {
            return $info;
        }

        $latest = $info['latest'] ?? null;

        $info['version']   = $live;
        $info['is_latest'] = $latest && version_compare($live, $latest, '>=');

        if ($info['is_latest']) {
            $info['releases_behind'] = 0;
        }

        return $info;
    }

    /**
     * `['vendor/pkg' => '1.2.3', ...]` from the live composer.lock. Reads
     * both packages + packages-dev so dev-only upgrades are pruned too.
     * Empty array if the lock can't be read.
     */
    protected function liveComposerVersions(): array
    {
        $lock = $this->readJsonFile(base_path('composer.lock'));

        if ($lock === null) return [];

        $versions = [];
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $pkg) {
            if (! empty($pkg['name']) && ! empty($pkg['version'])) {
                $versions[$pkg['name']] = ltrim($pkg['version'], 'v');
            }
        }

        return $versions;
    }

    /**
     * `['alpinejs' => '3.13.0', ...]` from the live package-lock.json.
     * Supports lock v1 (`dependencies`) and v2/v3 (`packages`).
     * Empty array if the lock can't be read.
     */
    protected function liveNpmVersions(): array
    {
        $lock = $this->readJsonFile(base_path('package-lock.json'));

        if ($lock === null) return [];

        $versions = [];

        if (! empty($lock['packages'])) {
            foreach ($lock['packages'] as $path => $data) {
                if ($path === '' || empty($data['version'])) continue;
                $name = preg_replace('#^node_modules/#', '', $path);
                $versions[$name] = $data['version'];
            }
        } elseif (! empty($lock['dependencies'])) {
            foreach ($lock['dependencies'] as $name => $data) {
                $versions[$name] = ltrim($data['version'] ?? '', 'v^~');
            }
        }

        return $versions;
    }

    /**
     * Drop outdated.packages entries whose live installed version is already
     * at or past the cached `latest`, and refresh each kept entry's `current`
     * to match the live install. Recomputes total + the two security counts
     * (OSV-flagged and vendor-flagged) the widget/utility depend on.
     */
    protected function pruneOutdatedAgainstLive(array $ecosystem, array $liveVersions): array
    {
        if (empty($liveVersions)) return $ecosystem;

        $packages   = $ecosystem['outdated']['packages'] ?? [];
        $kept       = [];
        $secTotal   = 0;
        $vendorOnly = 0;

        foreach ($packages as $pkg) {
            $name   = $pkg['name']   ?? null;
            $latest = $pkg['latest'] ?? null;
            $live   = $name ? ($liveVersions[$name] ?? null) : null;

            if ($live && $latest && version_compare($live, $latest, '>=')) {
                continue;
            }

            if ($live) {
                $pkg['current'] = $live;
            }

            $kept[] = $pkg;

            if (! empty($pkg['security_update'])) {
                $secTotal++;
            }
            if (($pkg['security_source'] ?? null) === 'vendor') {
                $vendorOnly++;
            }
        }

        $ecosystem['outdated']['packages']                      = $kept;
        $ecosystem['outdated']['total']                         = count($kept);
        $ecosystem['outdated']['security_updates_total']        = $secTotal;
        $ecosystem['outdated']['vendor_security_updates_total'] = $vendorOnly;

        return $ecosystem;
    }

    // -------------------------------------------------------------------------
    // Laravel
    // -------------------------------------------------------------------------

    protected function laravelInfo(array $composerAudit, ?string $latest, ?int $behind = null): array
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

        $isLatest = $latest && version_compare($current, $latest, '>=');

        return [
            'version'                   => $current,
            'latest'                    => $latest,
            'is_latest'                 => $isLatest,
            'releases_behind'           => $isLatest ? 0 : $behind,
            'status'                    => $status,
            'label'                     => $labels[$status],
            'security_update_available' => ! $isLatest && $this->hasSecurityUpdateFor('laravel/framework', $composerAudit),
        ];
    }

    /**
     * Run a scan and overwrite the cache. Used by the scheduler, the
     * sentinel:scan command, and the manual "Scan now" / "Refresh" buttons.
     * Cached forever - the scheduler owns freshness.
     */
    public function refresh(): array
    {
        $platform = $this->fetchPlatformLatestVersions();

        $composer = $this->annotateOutdatedSecurity(
            array_merge($this->composerAudit(), [
                'outdated'  => $this->composerOutdated(),
                'installed' => $this->composerInstalledDirect(),
            ]),
            'composer'
        );

        $npm = $this->annotateOutdatedSecurity(
            array_merge($this->npmAudit(), [
                'outdated'  => $this->npmOutdated(),
                'installed' => $this->npmInstalledDirect(),
            ]),
            'npm'
        );

        // Attribute each vulnerable transitive package to the direct dependency
        // that pulls it in, so the utility can nest it under that parent.
        $composer = $this->annotateDependencyParents($composer, base_path('composer.lock'), 'composer');
        $npm      = $this->annotateDependencyParents($npm, base_path('package-lock.json'), 'npm');

        $result = [
            'statamic'   => $this->statamicInfo($composer, $platform['statamic'], $platform['statamic_behind'] ?? null),
            'laravel'    => $this->laravelInfo($composer, $platform['laravel'], $platform['laravel_behind'] ?? null),
            'php'        => $this->phpInfo($platform['php']),
            'license'    => $this->licenseInfo(),
            'composer'   => $composer,
            'npm'        => $npm,
            'audited_at' => now()->format('j M Y, H:i'),
        ];

        Cache::forever(self::CACHE_KEY, $result);
        $this->writeToDisk($result);

        app(HistoryService::class)->recordIfChanged($result);

        return $result;
    }

    /**
     * Fire the three platform-version HTTP requests (Statamic, Laravel, PHP
     * EOL) concurrently. Returns a map of parsed values; any individual
     * endpoint that fails comes back as null without affecting the others,
     * and a total pool failure (network adapter level) returns nulls across
     * the board.
     */
    protected function fetchPlatformLatestVersions(): array
    {
        try {
            $responses = Http::pool(fn ($pool) => [
                $pool->as('statamic')->timeout(5)->get(self::PACKAGIST_STATAMIC_API),
                $pool->as('laravel')->timeout(5)->get(self::PACKAGIST_LARAVEL_API),
                $pool->as('php')->timeout(5)->get(self::EOL_DATE_PHP_API),
            ]);
        } catch (\Throwable $e) {
            return ['statamic' => null, 'laravel' => null, 'php' => null];
        }

        return [
            'statamic' => $this->extractLatestStableVersion($responses['statamic'] ?? null, 'statamic/cms'),
            'laravel'  => $this->extractLatestStableVersion($responses['laravel']  ?? null, 'laravel/framework'),
            'php'      => $this->extractEolBranches($responses['php'] ?? null),
            // How many stable releases the installed version is behind the newest.
            'statamic_behind' => $this->countStableReleasesNewerThan($responses['statamic'] ?? null, 'statamic/cms', \Statamic\Statamic::version()),
            'laravel_behind'  => $this->countStableReleasesNewerThan($responses['laravel']  ?? null, 'laravel/framework', app()->version()),
        ];
    }

    /**
     * True only for a genuine, successful HTTP response.
     *
     * `Http::pool()` does not throw on a per-request connection failure - the
     * failing slot holds an `Illuminate\Http\Client\ConnectionException` object
     * instead of a `Response`, so the surrounding try/catch never fires. Calling
     * `->ok()` on that object is a fatal `Call to undefined method`. Guarding on
     * `instanceof Response` skips both null and exception slots cleanly.
     */
    protected function isOkResponse($response): bool
    {
        return $response instanceof \Illuminate\Http\Client\Response && $response->ok();
    }

    /**
     * Pluck the newest stable (X.Y.Z) version from a Packagist p2 response.
     * The p2 API returns versions newest-first; dev / RC / beta releases are
     * filtered out by the regex.
     */
    protected function extractLatestStableVersion($response, string $packageKey): ?string
    {
        if (! $this->isOkResponse($response)) {
            return null;
        }

        foreach ($response->json("packages.$packageKey", []) as $version) {
            $v = ltrim($version['version'] ?? '', 'v');
            if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $v)) {
                return $v;
            }
        }

        return null;
    }

    /**
     * Count stable (X.Y.Z) releases in a Packagist p2 response that are newer
     * than the installed version - i.e. how many releases behind it is. Counts
     * across majors (up to the newest), so the number can exceed what the core
     * updater shows (which is scoped to the composer constraint).
     */
    protected function countStableReleasesNewerThan($response, string $packageKey, ?string $current): int
    {
        if (! $this->isOkResponse($response) || ! $current) {
            return 0;
        }

        $current = ltrim($current, 'v');
        $count   = 0;

        foreach ($response->json("packages.$packageKey", []) as $version) {
            $v = ltrim($version['version'] ?? '', 'v');
            if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $v) && version_compare($v, $current, '>')) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Decode the endoflife.date branches array, or null on failure.
     */
    protected function extractEolBranches($response): ?array
    {
        if (! $this->isOkResponse($response)) {
            return null;
        }

        $branches = $response->json();

        return is_array($branches) ? $branches : null;
    }

    /**
     * For each package in the ecosystem's `outdated.packages`, set a
     * `security_update` flag indicating whether updating it would resolve a
     * known OSV advisory OR a marketplace-flagged security release (Composer
     * only). Adds `outdated.security_updates_total` and `vendor_security_updates_total`
     * alongside, where the latter counts vendor-flagged updates that have no
     * matching OSV advisory yet - useful for distinguishing the two in the UI.
     */
    protected function annotateOutdatedSecurity(array $ecosystem, string $ecosystemType): array
    {
        $packages    = $ecosystem['outdated']['packages'] ?? [];
        $count       = 0;
        $vendorOnly  = 0;
        $marketplace = $ecosystemType === 'composer' ? app(MarketplaceService::class) : null;

        foreach ($packages as $i => $pkg) {
            $name    = $pkg['name'] ?? '';
            $current = $pkg['current'] ?? '';

            $osvFlag    = $this->hasSecurityUpdateFor($name, $ecosystem);
            $vendorFlag = $marketplace && $name !== '' && $current !== ''
                ? $marketplace->hasSecurityReleaseAfter($name, $current)
                : false;

            $packages[$i]['security_update']        = $osvFlag || $vendorFlag;
            $packages[$i]['security_source']        = $this->resolveSecuritySource($osvFlag, $vendorFlag);

            if ($osvFlag || $vendorFlag) {
                $count++;
            }
            if ($vendorFlag && ! $osvFlag) {
                $vendorOnly++;
            }
        }

        $ecosystem['outdated']['packages']                        = $packages;
        $ecosystem['outdated']['security_updates_total']          = $count;
        $ecosystem['outdated']['vendor_security_updates_total']   = $vendorOnly;

        return $ecosystem;
    }

    // -------------------------------------------------------------------------
    // Dependency parent attribution
    //
    // The Security Issues list scans the whole dependency tree, so it surfaces
    // vulnerable transitive (indirect) packages the user can't `require`
    // directly. For each such package we resolve the direct dependency that
    // pulls it in, so the utility can nest it one level under that parent and
    // make the relationship visible. Stored as
    // `dependency_parents = ['indirect/pkg' => 'direct/parent', ...]`.
    // -------------------------------------------------------------------------

    protected function annotateDependencyParents(array $ecosystem, string $lockPath, string $type): array
    {
        $ecosystem['dependency_parents'] = [];

        try {
            $byPackage  = $ecosystem['by_package']
                ?? self::groupBySeverity($ecosystem['severities'] ?? []);
            $vulnerable = array_column($byPackage, 'name');
            $direct     = array_keys($ecosystem['installed'] ?? []);

            if (empty($vulnerable) || empty($direct)) {
                return $ecosystem;
            }

            $lock = $this->readJsonFile($lockPath);

            if ($lock === null) {
                return $ecosystem;
            }

            $graph = $type === 'composer'
                ? $this->composerRequireGraph($lock)
                : $this->npmRequireGraph($lock);

            if (empty($graph)) {
                return $ecosystem;
            }

            $ecosystem['dependency_parents'] = $this->attributeIndirectToDirect(
                $vulnerable, $direct, $graph
            );
        } catch (\Throwable $e) {
            $ecosystem['dependency_parents'] = [];
        }

        return $ecosystem;
    }

    /**
     * `name => [required names]` from composer.lock. Requires are filtered to
     * packages that actually exist in the lock, dropping `php`, `ext-*` and
     * other platform/meta requirements that aren't real packages.
     */
    protected function composerRequireGraph(array $lock): array
    {
        $all = array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []);

        $known = [];
        foreach ($all as $pkg) {
            if (! empty($pkg['name'])) {
                $known[$pkg['name']] = true;
            }
        }

        $graph = [];
        foreach ($all as $pkg) {
            $name = $pkg['name'] ?? null;
            if ($name === null) continue;

            $graph[$name] = array_values(array_filter(
                array_keys($pkg['require'] ?? []),
                fn($dep) => isset($known[$dep])
            ));
        }

        return $graph;
    }

    /**
     * `name => [dependency names]` from package-lock.json. Supports lock v2/v3
     * (`packages` keyed by node_modules path) and v1 (nested `dependencies`
     * with `requires`). A package appearing at several paths has its edges
     * merged.
     */
    protected function npmRequireGraph(array $lock): array
    {
        $graph = [];

        if (! empty($lock['packages'])) {
            foreach ($lock['packages'] as $path => $data) {
                if ($path === '') continue; // root project

                // Strip every nesting prefix so "a/node_modules/@scope/b" -> "@scope/b".
                $name = preg_replace('#^.*node_modules/#', '', $path);

                $deps = array_merge(
                    array_keys($data['dependencies'] ?? []),
                    array_keys($data['optionalDependencies'] ?? [])
                );

                $graph[$name] = array_values(array_unique(
                    array_merge($graph[$name] ?? [], $deps)
                ));
            }
        } elseif (! empty($lock['dependencies'])) {
            $walk = function ($deps) use (&$walk, &$graph) {
                foreach ($deps as $name => $data) {
                    $graph[$name] = array_values(array_unique(array_merge(
                        $graph[$name] ?? [],
                        array_keys($data['requires'] ?? [])
                    )));

                    if (! empty($data['dependencies'])) {
                        $walk($data['dependencies']);
                    }
                }
            };
            $walk($lock['dependencies']);
        }

        return $graph;
    }

    /**
     * Map each vulnerable *indirect* package to the direct dependency that
     * pulls it in. Runs a BFS from every direct dependency; a target is
     * attributed to the direct dep with the shortest path to it, tie-broken
     * alphabetically so the result is deterministic. Targets unreachable from
     * any direct dependency are left out (they render top-level).
     */
    protected function attributeIndirectToDirect(array $vulnerable, array $direct, array $graph): array
    {
        $directSet = array_flip($direct);

        $targets = array_flip(array_filter(
            $vulnerable,
            fn($name) => ! isset($directSet[$name])
        ));

        if (empty($targets)) {
            return [];
        }

        // best[child] = ['parent' => name, 'dist' => int]
        $best = [];

        foreach ($direct as $root) {
            if (! isset($graph[$root])) continue;

            $visited = [$root => true];
            $queue   = [[$root, 0]];

            while ($queue) {
                [$node, $dist] = array_shift($queue);

                foreach ($graph[$node] ?? [] as $dep) {
                    if (isset($visited[$dep])) continue;
                    $visited[$dep] = true;

                    $childDist = $dist + 1;

                    if (isset($targets[$dep])) {
                        $current = $best[$dep] ?? null;
                        if ($current === null
                            || $childDist < $current['dist']
                            || ($childDist === $current['dist'] && strcmp($root, $current['parent']) < 0)) {
                            $best[$dep] = ['parent' => $root, 'dist' => $childDist];
                        }
                    }

                    $queue[] = [$dep, $childDist];
                }
            }
        }

        $map = [];
        foreach ($best as $child => $info) {
            $map[$child] = $info['parent'];
        }

        return $map;
    }

    // -------------------------------------------------------------------------
    // Statamic
    // -------------------------------------------------------------------------

    protected function statamicInfo(array $composerAudit, ?string $latest, ?int $behind = null): array
    {
        $current  = \Statamic\Statamic::version();
        $isLatest = $latest && version_compare($current, $latest, '>=');

        $osvFlag    = $this->hasSecurityUpdateFor('statamic/cms', $composerAudit);
        $vendorFlag = ! $isLatest && app(MarketplaceService::class)
            ->hasSecurityReleaseAfter('statamic/cms', $current);

        return [
            'current'                   => $current,
            'latest'                    => $latest,
            'is_latest'                 => $isLatest,
            'releases_behind'           => $isLatest ? 0 : $behind,
            'status'                    => $isLatest ? 'ok' : ($latest ? 'outdated' : 'unknown'),
            'security_update_available' => ! $isLatest && ($osvFlag || $vendorFlag),
            'security_source'           => $this->resolveSecuritySource($osvFlag, $vendorFlag),
        ];
    }

    /**
     * Where the Statamic security signal came from. Reported on the platform
     * row so the CP can show why it's flagged - "vendor" means the Statamic
     * team marked the release as security in the marketplace; "osv" means a
     * public advisory matched; "both" means the advisory caught up with the
     * vendor flag. null means no signal.
     */
    protected function resolveSecuritySource(bool $osv, bool $vendor): ?string
    {
        if ($osv && $vendor) return 'both';
        if ($osv)            return 'osv';
        if ($vendor)         return 'vendor';
        return null;
    }

    // -------------------------------------------------------------------------
    // Statamic license
    //
    // Statamic models "renewal" as a version range, not a calendar date: when a
    // Pro license no longer covers the running version, the Outpost response
    // carries reason `outside_license_range` and StatamicLicense::needsRenewal()
    // returns true. There is no renewal-date field in the API - the actual date
    // lives in the customer's statamic.com account, which SiteLicense::url()
    // deep-links to. We read from Statamic's own cached Outpost response
    // (Cache::store('outpost'), 1-hour TTL); reading is normally offline, but a
    // cold cache can trigger one POST to outpost.statamic.com - acceptable here
    // since refresh() is already the network path. Everything is guarded so the
    // addon degrades to an "unsupported" shell on installs where the licensing
    // classes/methods are absent (very old Statamic) or licensing isn't booted.
    //
    // The derived `status` collapses the flags into one value the views switch on:
    //   free    - site isn't running Statamic Pro (nothing to license)
    //   trial   - Pro on a test/development domain (Statamic's own trial mode)
    //   unknown - could not verify (Outpost request failed / offline)
    //   renewal - license valid, but doesn't cover the installed version
    //   invalid - license invalid for another reason
    //   ok       - licensed and covering the installed version
    // -------------------------------------------------------------------------

    protected function licenseInfo(): array
    {
        if (! class_exists(\Statamic\Licensing\LicenseManager::class) || ! class_exists(\Statamic\Statamic::class)) {
            return ['supported' => false];
        }

        try {
            $manager  = app(\Statamic\Licensing\LicenseManager::class);
            $statamic = $manager->statamic();

            $pro           = \Statamic\Statamic::pro();
            $requestFailed = method_exists($manager, 'requestFailed')   ? (bool) $manager->requestFailed()   : false;
            $onTestDomain  = method_exists($manager, 'isOnTestDomain')  ? (bool) $manager->isOnTestDomain()  : false;
            $needsRenewal  = method_exists($statamic, 'needsRenewal')   ? (bool) $statamic->needsRenewal()   : false;
            $valid         = method_exists($manager, 'statamicValid')   ? (bool) $manager->statamicValid()
                           : (method_exists($manager, 'valid')         ? (bool) $manager->valid() : true);

            // The licensed version window (versions, not dates). Present only
            // when the license explicitly falls outside its covered range.
            $range = null;
            $raw   = method_exists($manager, 'response') ? $manager->response('statamic', []) : [];
            if (is_array($raw) && ($raw['reason'] ?? null) === 'outside_license_range' && ! empty($raw['range'])) {
                $range = ['start' => $raw['range'][0] ?? null, 'end' => $raw['range'][1] ?? null];
            }

            // Deep link to the statamic.com account page, where the real
            // renewal date lives. Guarded - SiteLicense may be absent.
            $accountUrl = null;
            try {
                $site = method_exists($manager, 'site') ? $manager->site() : null;
                if ($site && method_exists($site, 'url')) {
                    $accountUrl = $site->url();
                }
            } catch (\Throwable $e) {
                // No account URL - not fatal.
            }

            if (! $pro) {
                $status = 'free';
            } elseif ($onTestDomain) {
                $status = 'trial';
            } elseif ($requestFailed) {
                $status = 'unknown';
            } elseif ($needsRenewal) {
                $status = 'renewal';
            } elseif (! $valid) {
                $status = 'invalid';
            } else {
                $status = 'ok';
            }

            return [
                'supported'      => true,
                'status'         => $status,
                'pro'            => $pro,
                'valid'          => $valid,
                'needs_renewal'  => $needsRenewal,
                'request_failed' => $requestFailed,
                'on_test_domain' => $onTestDomain,
                'version'        => \Statamic\Statamic::version(),
                'range'          => $range,
                'account_url'    => $accountUrl,
            ];
        } catch (\Throwable $e) {
            return ['supported' => false];
        }
    }

    /**
     * Does the given package have at least one OSV advisory with a fix available?
     * OSV only catalogs security advisories, so any hit here is by definition
     * a security update - never a cosmetic/bug-fix upgrade.
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

    // -------------------------------------------------------------------------
    // PHP
    // -------------------------------------------------------------------------

    protected function phpInfo(?array $branches): array
    {
        $full       = PHP_VERSION;
        $majorMinor = implode('.', array_slice(explode('.', $full), 0, 2));

        // Absolute newest stable across all branches, not just the user's own.
        // Without this, a site on 8.4.20 (latest 8.4 patch) would read as
        // "up to date" even when 8.5.x has shipped - matches how Statamic and
        // Laravel surface cross-major upgrades.
        $latest = $this->latestStablePhpVersion($branches);
        $behind = $this->phpReleasesBehind($branches, $full);

        if ($branches) {
            try {
                $branch = collect($branches)->firstWhere('cycle', $majorMinor);

                if ($branch) {
                    $today        = now();
                    $activeEnds   = \Carbon\Carbon::parse($branch['support']);
                    $securityEnds = \Carbon\Carbon::parse($branch['eol']);

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
                        'version'         => $full,
                        'latest'          => $latest,
                        'is_latest'       => $latest && version_compare($full, $latest, '>='),
                        'releases_behind' => $behind,
                        'status'          => $status,
                        'label'           => $label,
                    ];
                }
            } catch (\Throwable $e) {
                // Fall through to unknown
            }
        }

        return [
            'version'         => $full,
            'latest'          => $latest,
            'is_latest'       => $latest && version_compare($full, $latest, '>='),
            'releases_behind' => $behind,
            'status'          => 'unknown',
            'label'           => 'Unknown',
        ];
    }

    /**
     * Best-effort count of PHP releases the installed version is behind the
     * newest. endoflife.date gives only each branch's latest patch (no full
     * release list), so we derive counts from patch numbers: the remaining
     * patches in the installed branch, plus every release (`latest patch + 1`,
     * since patches start at .0) in each newer branch up to the latest. Exact
     * for the common same-branch case (8.5.5 -> 8.5.7 = 2), approximate across
     * branches. Skips future/unreleased branches like latestStablePhpVersion().
     */
    protected function phpReleasesBehind(?array $branches, string $current): int
    {
        if (! $branches) return 0;

        $current  = ltrim($current, 'v');
        $curParts = explode('.', $current);
        if (count($curParts) < 3) return 0;

        $curMinor = $curParts[0] . '.' . $curParts[1];
        $curPatch = (int) $curParts[2];

        $latest = $this->latestStablePhpVersion($branches);
        if (! $latest) return 0;

        $today  = now();
        $behind = 0;

        foreach ($branches as $branch) {
            $cycle   = $branch['cycle']  ?? null;   // e.g. "8.5"
            $bLatest = $branch['latest'] ?? null;   // e.g. "8.5.7"

            if (! $cycle || ! $bLatest || ! preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $bLatest)) {
                continue;
            }

            if (! empty($branch['releaseDate'])) {
                try {
                    if (\Carbon\Carbon::parse($branch['releaseDate'])->gt($today)) continue;
                } catch (\Throwable $e) {
                    // Bad date - fall through and consider the branch.
                }
            }

            // Only branches from the installed one up to the latest count.
            if (version_compare($cycle . '.0', $curMinor . '.0', '<')) continue;
            if (version_compare($bLatest, $latest, '>')) continue;

            $bLatestPatch = (int) explode('.', $bLatest)[2];

            if ($cycle === $curMinor) {
                $behind += max(0, $bLatestPatch - $curPatch);
            } else {
                $behind += $bLatestPatch + 1;
            }
        }

        return $behind;
    }

    /**
     * Highest stable X.Y.Z across all endoflife.date branches. Skips branches
     * whose `releaseDate` is in the future (upcoming versions that haven't
     * shipped yet) and any non-stable `latest` strings.
     */
    protected function latestStablePhpVersion(?array $branches): ?string
    {
        if (! $branches) return null;

        $today   = now();
        $highest = null;

        foreach ($branches as $branch) {
            $latest = $branch['latest'] ?? null;
            if (! $latest || ! preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $latest)) {
                continue;
            }

            if (! empty($branch['releaseDate'])) {
                try {
                    if (\Carbon\Carbon::parse($branch['releaseDate'])->gt($today)) {
                        continue;
                    }
                } catch (\Throwable $e) {
                    // Bad date - fall through and consider the version
                }
            }

            if ($highest === null || version_compare($latest, $highest, '>')) {
                $highest = $latest;
            }
        }

        return $highest;
    }

    // -------------------------------------------------------------------------
    // Composer audit via OSV
    // -------------------------------------------------------------------------

    protected function composerAudit(): array
    {
        $lock = $this->readJsonFile(base_path('composer.lock'));

        if ($lock === null) {
            return ['status' => 'unavailable', 'message' => 'composer.lock not found.', 'severities' => [], 'counts' => [], 'total_packages' => 0, 'total_vulns' => 0];
        }

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
        $lock = $this->readJsonFile(base_path('package-lock.json'));

        if ($lock === null) {
            return ['status' => 'unavailable', 'message' => 'package-lock.json not found.', 'severities' => [], 'counts' => [], 'total_packages' => 0, 'total_vulns' => 0];
        }

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

            // OSV `id` is usually a GHSA; the CVE (when one exists) lives in
            // `aliases`. Surface it so the utility can label each issue by CVE.
            $cve = null;
            foreach ($vuln['aliases'] ?? [] as $alias) {
                if (is_string($alias) && str_starts_with($alias, 'CVE-')) {
                    $cve = $alias;
                    break;
                }
            }

            $severities[$severity]['count']++;

            if (! in_array($pair['package'], $severities[$severity]['packages'])) {
                $severities[$severity]['packages'][] = $pair['package'];
            }

            $severities[$severity]['vulns'][] = [
                'id'            => $pair['id'],
                'cve'           => $cve,
                'severity'      => $severity,
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
            'by_package'     => self::groupBySeverity($severities),
        ];
    }

    /**
     * Roll up the per-severity vuln list into a per-package summary, sorted
     * by highest severity then name. Computed at scan time so the utility
     * view can render a cached snapshot without re-grouping on every load.
     * Public + static so views can fall back to it for cache rows written
     * before this method existed.
     */
    public static function groupBySeverity(array $severities): array
    {
        $rank = ['CRITICAL' => 5, 'HIGH' => 4, 'MEDIUM' => 3, 'LOW' => 2, 'UNKNOWN' => 1];

        $byPackage = [];
        foreach (['CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'UNKNOWN'] as $sev) {
            foreach ($severities[$sev]['vulns'] ?? [] as $v) {
                $name = $v['package'];
                if (! isset($byPackage[$name])) {
                    $byPackage[$name] = ['name' => $name, 'highest' => $sev, 'count' => 0, 'vulns' => []];
                }
                if ($rank[$sev] > $rank[$byPackage[$name]['highest']]) {
                    $byPackage[$name]['highest'] = $sev;
                }
                $byPackage[$name]['count']++;

                // Per-advisory detail so the utility can drill into a package's
                // issues. Iterating severities high-to-low keeps this ordered.
                $byPackage[$name]['vulns'][] = [
                    'id'       => $v['id'] ?? null,
                    'cve'      => $v['cve'] ?? null,
                    'severity' => $v['severity'] ?? $sev,
                    'url'      => $v['url'] ?? null,
                ];
            }
        }

        uasort($byPackage, function ($a, $b) use ($rank) {
            $cmp = $rank[$b['highest']] - $rank[$a['highest']];
            return $cmp !== 0 ? $cmp : strcmp($a['name'], $b['name']);
        });

        return array_values($byPackage);
    }

    /**
     * Fetch full vulnerability details for the given IDs, concurrently.
     * Failed lookups are silently omitted - those vulns fall back to UNKNOWN.
     */
    protected function fetchVulnDetails(array $ids): array
    {
        $details = [];

        foreach (array_chunk($ids, 50) as $chunk) {
            try {
                $responses = Http::pool(function ($pool) use ($chunk) {
                    return array_map(
                        fn($id) => $pool->as($id)->timeout(10)->get('https://api.osv.dev/v1/vulns/' . $id),
                        $chunk
                    );
                });
            } catch (\Throwable $e) {
                continue;
            }

            foreach ($chunk as $id) {
                $response = $responses[$id] ?? null;
                if (! $this->isOkResponse($response)) continue;

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
            // CVSS vector string - extract base score via regex
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
        $manifest = $this->readJsonFile(base_path('composer.json'));
        $lock     = $this->readJsonFile(base_path('composer.lock'));

        if ($manifest === null || $lock === null) {
            return [];
        }

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
                    fn($name) => $pool->as($name)->timeout(5)->get("https://repo.packagist.org/p2/{$name}.json"),
                    $toCheck
                );
            });
        } catch (\Throwable $e) {
            return ['total' => 0, 'packages' => [], 'error' => true];
        }

        $outdated = [];

        foreach ($toCheck as $name) {
            $response = $responses[$name] ?? null;
            if (! $this->isOkResponse($response)) continue;

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
        $manifest = $this->readJsonFile(base_path('package.json'));
        $lock     = $this->readJsonFile(base_path('package-lock.json'));

        if ($manifest === null || $lock === null) {
            return [];
        }

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
                    fn($name) => $pool->as($name)->timeout(5)->get("https://registry.npmjs.org/{$name}/latest"),
                    $toCheck
                );
            });
        } catch (\Throwable $e) {
            return ['total' => 0, 'packages' => [], 'error' => true];
        }

        $outdated = [];

        foreach ($toCheck as $name) {
            $response = $responses[$name] ?? null;
            if (! $this->isOkResponse($response)) continue;

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
