<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no,address=no,email=no,date=no,url=no">
    <title>Statamic Package Status Report</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-size:14px; color:#1e293b;">

<div style="display:none; max-height:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:#f1f5f9;">
    {{ $preheader }}
</div>
<div style="display:none; max-height:0; overflow:hidden;">
    &#847; &zwnj; &nbsp; &#847; &zwnj; &nbsp; &#847; &zwnj; &nbsp; &#847; &zwnj; &nbsp;
</div>

@php
    $statamic = $audit['statamic'];
    $laravel  = $audit['laravel'];
    $php      = $audit['php'];
    $composer = $audit['composer'];
    $npm      = $audit['npm'];

    // Build the badge for each row: pill text + colour, and an optional
    // `detail` line shown to its left. A null `text` means no pill.
    $isPatchOnly = function ($current, $latest) {
        if (! $current || ! $latest) return false;
        $c = explode('.', $current);
        $l = explode('.', $latest);
        // Same major + minor, only patch differs → understated update.
        return ($c[0] ?? null) === ($l[0] ?? null) && ($c[1] ?? null) === ($l[1] ?? null);
    };

    $isMajorBehind = function ($current, $latest, $platform = null) {
        if (! $current || ! $latest) return false;
        $c = explode('.', $current);
        $l = explode('.', $latest);
        // PHP ships each X.Y as a distinct release branch with its own EOL
        // window, so 8.4 → 8.5 is effectively a major bump even though both
        // share a leading "8". Treat any X.Y change as major-behind for PHP.
        if ($platform === 'PHP') {
            return ($c[0] ?? null) !== ($l[0] ?? null)
                || ($c[1] ?? null) !== ($l[1] ?? null);
        }
        return ($c[0] ?? null) !== ($l[0] ?? null);
    };

    $platformBadge = function (array $p, ?string $platform = null) use ($isPatchOnly, $isMajorBehind) {
        $status   = $p['status'] ?? 'unknown';
        $security = ! empty($p['security_update_available']);
        $current  = $p['current'] ?? $p['version'] ?? null;
        $latest   = $p['latest']  ?? null;
        $outdated = $latest && $current && version_compare($current, $latest, '<');

        if ($security)               return ['text' => 'Security update', 'colour' => '#dc2626', 'detail' => $current . ' → ' . $latest];
        if ($status === 'eol')       return ['text' => 'End of life',     'colour' => '#dc2626', 'detail' => $current];
        if ($status === 'security')  return ['text' => 'Security only',   'colour' => '#b45309', 'detail' => $current];
        if ($outdated) {
            // Patch-only bumps (e.g. 8.4.18 → 8.4.20) get no pill - the
            // version arrow conveys the change without sounding the alarm.
            if ($isPatchOnly($current, $latest)) {
                return ['text' => null, 'colour' => null, 'detail' => $current . ' → ' . $latest];
            }
            // Only a different major earns "Outdated"; minor bumps are
            // routine updates and read as "Update available" in blue,
            // matching the ecosystem badges.
            if ($isMajorBehind($current, $latest, $platform)) {
                return ['text' => 'Outdated', 'colour' => '#b45309', 'detail' => $current . ' → ' . $latest];
            }
            return ['text' => 'Update available', 'colour' => '#3b82f6', 'detail' => $current . ' → ' . $latest];
        }
        if (in_array($status, ['ok', 'active'])) return ['text' => 'Up to date', 'colour' => '#10b981', 'detail' => $current];

        return ['text' => 'Unknown', 'colour' => '#94a3b8', 'detail' => $current ?? '-'];
    };

    $ecosystemBadge = function (array $eco) {
        $status     = $eco['status']     ?? 'unknown';
        $vulns      = (int) ($eco['total_vulns']                            ?? 0);
        $outdated   = (int) ($eco['outdated']['total']                      ?? 0);
        // Vendor-flagged security releases that don't yet have an OSV advisory.
        // Counted toward the security pill so the email matches the CP badge.
        $vendorOnly = (int) ($eco['outdated']['vendor_security_updates_total'] ?? 0);
        $totalSec   = $vulns + $vendorOnly;

        if ($status === 'unavailable') return ['text' => 'Not found',    'colour' => '#94a3b8', 'detail' => 'Lock file not found'];
        if ($status === 'error')       return ['text' => 'Check failed', 'colour' => '#dc2626', 'detail' => 'Could not reach the registry'];

        $updatesText = $outdated . ' ' . \Illuminate\Support\Str::plural('update', $outdated) . ' available';
        $vulnsText   = $totalSec . ' security ' . \Illuminate\Support\Str::plural('issue', $totalSec);

        // Vulns take the (red) pill when present; updates fall back to the
        // detail line so the row never duplicates itself.
        if ($totalSec > 0) {
            return ['text' => $vulnsText, 'colour' => '#dc2626', 'detail' => $outdated > 0 ? $updatesText : ''];
        }

        // No vulns: updates own the pill (blue), no detail line needed.
        if ($outdated > 0) {
            return ['text' => $updatesText, 'colour' => '#3b82f6', 'detail' => ''];
        }

        return ['text' => 'Up to date', 'colour' => '#10b981', 'detail' => ''];
    };

    $rows = [
        ['kind' => 'platform', 'label' => 'Statamic', 'description' => 'The CMS that powers your website',           'data' => $statamic],
        ['kind' => 'platform', 'label' => 'Laravel',  'description' => 'The framework Statamic is built on',         'data' => $laravel],
        ['kind' => 'platform', 'label' => 'PHP',      'description' => 'The server-side language that runs everything', 'data' => $php],
        ['kind' => 'eco',      'label' => 'Composer', 'description' => 'Third-party PHP packages your site uses',    'data' => $composer],
        ['kind' => 'eco',      'label' => 'npm',      'description' => 'Third-party JavaScript packages your site uses', 'data' => $npm],
    ];

    $totalVulns    = ($composer['total_vulns']        ?? 0) + ($npm['total_vulns']        ?? 0)
                   + ($composer['outdated']['vendor_security_updates_total'] ?? 0)
                   + ($npm['outdated']['vendor_security_updates_total']      ?? 0);
    $totalOutdated = ($composer['outdated']['total']  ?? 0) + ($npm['outdated']['total']  ?? 0);

    $platformEol = in_array($statamic['status'] ?? '', ['eol'])
                || in_array($laravel['status']  ?? '', ['eol'])
                || in_array($php['status']      ?? '', ['eol']);

    $securityUpdate = ($statamic['security_update_available'] ?? false)
                   || ($laravel['security_update_available']  ?? false);

    $needsAttention = $totalVulns > 0 || $platformEol || $securityUpdate;

    // Mirror the row-level "Outdated" amber pill at the banner: any platform
    // a full major behind earns its own tier between needs-attention (red)
    // and routine updates (blue).
    $platformMajorBehind = false;
    foreach ([['Statamic', $statamic], ['Laravel', $laravel], ['PHP', $php]] as [$_name, $_p]) {
        $_current = $_p['current'] ?? $_p['version'] ?? null;
        $_latest  = $_p['latest']  ?? null;
        if ($_latest && $_current && version_compare($_current, $_latest, '<') && $isMajorBehind($_current, $_latest, $_name)) {
            $platformMajorBehind = true;
            break;
        }
    }

    $introDetail = null;
    if ($needsAttention) {
        $intro        = 'Your Statamic website needs attention';
        $introDetail  = 'Security or platform issues were found.';
        $statusAccent = '#ef4444';
    } elseif ($platformMajorBehind) {
        $intro        = 'One or more platforms a major version behind.';
        $statusAccent = '#b45309';
    } elseif ($totalOutdated > 0) {
        $intro        = 'Your Statamic website is in good health, routine updates available.';
        $statusAccent = '#3b82f6';
    } else {
        $intro        = 'Your Statamic website is fully up to date and in good health.';
        $statusAccent = '#10b981';
    }
@endphp

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f1f5f9;">
<tr>
<td align="center" style="padding:32px 16px;">
<table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="max-width:640px; width:100%; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.1);">
<tr><td>

    {{-- Header --}}
    <div style="background:#0f172a; padding:24px 32px;">
        <div style="font-size:18px; font-weight:700; letter-spacing:-0.02em;">
            <a href="https://{{ $host }}" style="color:#ffffff; text-decoration:none;">{{ $host }}</a>
        </div>
        <div style="font-size:13px; color:#cbd5e1; margin-top:4px;">Statamic Package Status Report &nbsp;·&nbsp; {{ $audit['audited_at'] }}</div>
    </div>

    <div style="padding:28px 32px;">

        {{-- Status banner --}}
        <div style="background:{{ $statusAccent }}1a; border-left:3px solid {{ $statusAccent }}; padding:14px 16px; border-radius:6px; margin-bottom:24px;">
            <div style="font-size:15px; font-weight:600; color:#0f172a; line-height:1.4;">{{ $intro }}</div>
            @if ($introDetail)
                <div style="font-size:13px; font-weight:400; color:#475569; line-height:1.4; margin-top:4px;">{{ $introDetail }}</div>
            @endif
        </div>

        {{-- Always-visible rows: Statamic / Laravel / PHP / Composer / npm --}}
        @foreach ($rows as $row)
            @php
                $b = $row['kind'] === 'platform' ? $platformBadge($row['data'], $row['label']) : $ecosystemBadge($row['data']);
            @endphp
            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; margin-bottom:10px;">
                <tr>
                    <td style="padding:12px 16px; vertical-align:middle;">
                        <div style="font-size:13px; font-weight:600; color:#0f172a;">{{ $row['label'] }}</div>
                        <div style="font-size:12px; color:#475569; margin-top:3px;">{{ $row['description'] }}</div>
                    </td>
                    <td align="right" style="padding:12px 16px; font-size:12px; color:#475569; vertical-align:middle; white-space:nowrap; font-variant-numeric:tabular-nums;">
                        @if (! empty($b['detail']))
                            <span style="color:#475569;">{{ $b['detail'] }}</span>
                        @endif
                        @if (! empty($b['text']))
                            <span style="display:inline-block; margin-left:10px; font-size:11px; font-weight:600; padding:2px 8px; border-radius:4px; color:{{ $b['colour'] }}; border:1px solid {{ $b['colour'] }}; background:#fff;">{{ $b['text'] }}</span>
                        @endif
                    </td>
                </tr>
            </table>
        @endforeach

        @if (! empty($sentinelDevEmail))
            {{-- CTA --}}
            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; margin-top:14px;">
                <tr>
                    <td align="center" style="padding:4px 0 8px;">
                        <a href="mailto:{{ $sentinelDevEmail }}?subject={{ rawurlencode('Maintenance enquiry for ' . $host) }}"
                           style="display:inline-block; background:#0f172a; color:#ffffff; font-size:14px; font-weight:600; text-decoration:none; padding:12px 28px; border-radius:8px;">
                            Need help with your website?
                        </a>
                    </td>
                </tr>
            </table>
        @endif

    </div>

    {{-- Footer --}}
    @php
        $_devName = $sentinelDevName ?? null;
        $_devUrl  = $sentinelDevUrl ?? null;
        $sentinelFooterAttribution = $_devName
            ? ($_devUrl
                ? '<a href="' . e($_devUrl) . '" style="color:#64748b; text-decoration:underline;">' . e($_devName) . '</a>'
                : e($_devName))
            : 'Sentinel for Statamic';
    @endphp
    <div style="background:#f8fafc; border-top:1px solid #e2e8f0; padding:20px 32px;">
        <div style="font-size:12px; color:#64748b;">
            This report was generated by {!! $sentinelFooterAttribution !!}.
        </div>
    </div>

</td></tr>
</table>
</td>
</tr>
</table>

</body>
</html>
