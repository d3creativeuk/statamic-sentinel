<?php

namespace D3Creative\Sentinel\Services;

use Illuminate\Support\Facades\Http;

/**
 * Looks up release metadata from the Statamic marketplace API. We use this
 * to read the per-release `security` flag the Statamic team sets when they
 * cut a security release, since the public OSV / GHSA advisory feeds
 * typically lag behind that flag by days or weeks.
 *
 * Endpoint is public (no licence header) and works for both `statamic/cms`
 * and any addon published through the marketplace - non-marketplace
 * packages 404 and we treat that as "no vendor data, fall back to OSV".
 */
class MarketplaceService
{
    const RELEASES_URL = 'https://statamic.com/api/v1/marketplace/packages/{package}/releases';

    /**
     * Per-instance cache so a single scan doesn't refetch the same package's
     * release feed across statamicInfo() + per-addon annotation passes.
     *
     * @var array<string, array<int, array{version: string, security: bool, released_at: ?string}>>
     */
    protected array $releaseCache = [];

    /**
     * Negative-cache packages that aren't on the marketplace so we don't keep
     * hammering 404s within one scan (the instance lives for a single scan).
     * Keyed by package name.
     *
     * @var array<string, true>
     */
    protected array $notOnMarketplace = [];

    /**
     * Set when statamic.com refuses a connection, so the rest of the scan
     * stops waiting on it (each lookup would otherwise hit its own timeout).
     * Failing open: no vendor flags, OSV still applies.
     */
    protected bool $unreachable = false;

    public function enabled(): bool
    {
        return (bool) config('statamic-sentinel.vendor_security_check', true);
    }

    /**
     * Does the marketplace flag any release newer than $currentVersion as a
     * security release? Returns false on network errors, 404s, or when the
     * feature is disabled - callers should treat this as a supplemental
     * signal on top of OSV, not a replacement.
     */
    public function hasSecurityReleaseAfter(string $package, string $currentVersion): bool
    {
        foreach ($this->releasesAfter($package, $currentVersion) as $release) {
            if (! empty($release['security'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * All marketplace releases strictly newer than $currentVersion, newest
     * first. Empty on any failure (disabled, network error, 404, malformed
     * response).
     */
    public function releasesAfter(string $package, string $currentVersion): array
    {
        $current = ltrim($currentVersion, 'v');

        // A branch install (dev-main, 6.x-dev) isn't comparable with releases;
        // version_compare() would treat every release as newer.
        if ($current === '' || str_starts_with($current, 'dev-') || str_ends_with($current, '-dev') || ! preg_match('/^\d/', $current)) {
            return [];
        }

        $all = $this->releases($package);

        return array_values(array_filter($all, function ($release) use ($current) {
            $version = ltrim($release['version'] ?? '', 'v');
            return $version !== '' && version_compare($version, $current, '>');
        }));
    }

    /**
     * Raw release list for a package, newest first. Hits the marketplace
     * once per package per service instance. Returns [] when disabled, on
     * any network failure, or for packages not published on the marketplace.
     */
    public function releases(string $package): array
    {
        if (! $this->enabled() || $this->unreachable) {
            return [];
        }

        if (isset($this->releaseCache[$package])) {
            return $this->releaseCache[$package];
        }

        if (isset($this->notOnMarketplace[$package])) {
            return [];
        }

        $url = str_replace('{package}', $package, self::RELEASES_URL);

        try {
            $response = AuditService::prepare(Http::acceptJson(), 5)->get($url, self::QUERY);
        } catch (\Throwable $e) {
            $response = $e;
        }

        return $this->store($package, $response);
    }

    /**
     * Fetch several packages' release feeds in one concurrent round instead
     * of one blocking request each (35 s for seven addons when statamic.com
     * hangs). Results land in the per-scan cache that releases() reads, so
     * callers carry on asking package by package. If the pool can't run,
     * nothing is cached and releases() looks them up one at a time.
     *
     * @param array<int, string> $packages
     */
    public function prefetch(array $packages): void
    {
        if (! $this->enabled() || $this->unreachable) {
            return;
        }

        $todo = array_values(array_filter(
            array_unique($packages),
            fn ($p) => is_string($p) && $p !== '' && ! isset($this->releaseCache[$p]) && ! isset($this->notOnMarketplace[$p])
        ));

        if (count($todo) < 2) {
            return;
        }

        try {
            $responses = Http::pool(fn ($pool) => array_map(
                fn ($p) => AuditService::prepare($pool->as($p)->acceptJson(), 5)
                    ->get(str_replace('{package}', $p, self::RELEASES_URL), self::QUERY),
                $todo
            ));
        } catch (\Throwable $e) {
            return;
        }

        foreach ($todo as $p) {
            $this->store($p, $responses[$p] ?? null);
        }
    }

    // perPage=50 keeps us under a single page for ~all real-world upgrade
    // ranges (Statamic 6.x has shipped ~30 releases in a year), without
    // paying for full history we'll never read.
    const QUERY = ['perPage' => 50, 'page' => 1];

    /**
     * Cache one package's answer: a release list, a 404 (not on the
     * marketplace), or [] for anything else. A refused connection marks
     * statamic.com unreachable for the rest of the scan.
     */
    protected function store(string $package, $response): array
    {
        if (! $response instanceof \Illuminate\Http\Client\Response) {
            if ($response instanceof \Illuminate\Http\Client\ConnectionException) {
                $this->unreachable = true;
            }

            return $this->releaseCache[$package] = [];
        }

        if ($response->status() === 404) {
            $this->notOnMarketplace[$package] = true;
            return [];
        }

        if (! $response->ok() || AuditService::bodyTooLarge($response)) {
            return $this->releaseCache[$package] = [];
        }

        $data = $response->json('data');

        if (! is_array($data)) {
            return $this->releaseCache[$package] = [];
        }

        $releases = [];
        foreach ($data as $release) {
            $version = ltrim($release['version'] ?? '', 'v');
            if (! preg_match('/^[0-9]+\.[0-9]+\.[0-9]+/', $version)) {
                continue;
            }

            $releases[] = [
                'version'     => $version,
                'security'    => (bool) ($release['security'] ?? false),
                'released_at' => $release['date'] ?? null,
            ];
        }

        return $this->releaseCache[$package] = $releases;
    }
}
