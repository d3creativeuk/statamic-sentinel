<?php

namespace D3Creative\Sentinel\Support;

/**
 * Resolves which host(s) a Sentinel report should reference. On a multisite
 * install the audit itself is shared across every site (one codebase), so the
 * only per-site element of a report is its label - the header host + email
 * subject. This returns every site's host so those can list the whole install
 * rather than only the primary site.
 *
 * Statamic-aware, but never depends on it: if the Sites system is unavailable
 * (not booted, older Statamic, or any error) it falls back to the single
 * config('app.url') host, so scheduled/CLI sends and non-multisite installs
 * behave exactly as before and a send is never broken by this lookup.
 */
class ReportHosts
{
    /**
     * Unique host for every Statamic site, newest-config order, default first.
     * Path/locale multisites that share a domain collapse to a single host.
     * Always returns at least one entry (the config fallback).
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        try {
            if (class_exists(\Statamic\Facades\Site::class)) {
                $hosts = \Statamic\Facades\Site::all()
                    ->map(fn ($site) => parse_url($site->absoluteUrl(), PHP_URL_HOST))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if (! empty($hosts)) {
                    return $hosts;
                }
            }
        } catch (\Throwable $e) {
            // Fall through to the config-based single-host fallback below.
        }

        return [parse_url(config('app.url'), PHP_URL_HOST) ?: 'site'];
    }

    /**
     * Comma-separated host list for the report header and email subject.
     */
    public static function label(): string
    {
        return implode(', ', self::all());
    }

    /**
     * Grammatical host list for prose: "a.test", "a.test and b.test",
     * "a.test, b.test and c.test".
     */
    public static function sentence(): string
    {
        $hosts = self::all();

        if (count($hosts) <= 1) {
            return $hosts[0] ?? '';
        }

        $last = array_pop($hosts);

        return implode(', ', $hosts) . ' and ' . $last;
    }
}
