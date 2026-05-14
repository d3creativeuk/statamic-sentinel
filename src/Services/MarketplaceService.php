<?php

namespace D3Creative\Sentinel\Services;

use Illuminate\Support\Facades\Http;

/**
 * Looks up release metadata from the Statamic marketplace API. We use this
 * to read the per-release `security` flag the Statamic team sets when they
 * cut a security release, since the public OSV / GHSA advisory feeds
 * typically lag behind that flag by days or weeks.
 *
 * Endpoint is public (no license header) and works for both `statamic/cms`
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
     * hammering 404s for every Sentinel scan. Keyed by package name.
     *
     * @var array<string, true>
     */
    protected array $notOnMarketplace = [];

    public function enabled(): bool
    {
        return (bool) config('sentinel.vendor_security_check', true);
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

        if ($current === '') {
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
        if (! $this->enabled()) {
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
            // perPage=50 keeps us under a single page for ~all real-world
            // upgrade ranges (Statamic 6.x has shipped ~30 releases in a
            // year), without paying for full history we'll never read.
            $response = Http::timeout(5)
                ->acceptJson()
                ->get($url, ['perPage' => 50, 'page' => 1]);
        } catch (\Throwable $e) {
            return $this->releaseCache[$package] = [];
        }

        if ($response->status() === 404) {
            $this->notOnMarketplace[$package] = true;
            return [];
        }

        if (! $response->ok()) {
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
