<?php

namespace D3Creative\Sentinel\Support;

/**
 * Security issue counts for one ecosystem block of the audit, shared by the
 * widget and the utility so their numbers agree. The total is OSV advisories
 * plus vendor-flagged security releases that have no matching advisory yet.
 */
class SecuritySummary
{
    public static function total(array $ecosystem): int
    {
        return array_sum(array_map('intval', $ecosystem['counts'] ?? [])) + static::vendorOnlyCount($ecosystem);
    }

    public static function vendorOnlyCount(array $ecosystem): int
    {
        return (int) ($ecosystem['outdated']['vendor_security_updates_total'] ?? 0);
    }

    /**
     * Outdated packages flagged only by the vendor (security_source "vendor").
     */
    public static function vendorOnlyPackages(array $ecosystem): array
    {
        return array_values(array_filter(
            $ecosystem['outdated']['packages'] ?? [],
            fn ($pkg) => is_array($pkg) && ($pkg['security_source'] ?? null) === 'vendor'
        ));
    }
}
