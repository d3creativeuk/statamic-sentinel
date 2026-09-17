<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no,address=no,email=no,date=no,url=no">
    <title>Statamic Package Status Report</title>
    <style>
        /* Summary rows always stack the version + pills under the description.
           On narrow screens the remaining two-column rows (package lists)
           stack too. */
        @media only screen and (max-width:480px) {
            .sentinel-row-cell { display:block !important; width:100% !important; box-sizing:border-box !important; }
            .sentinel-row-meta {
                text-align:left !important;
                padding-top:2px !important;
                white-space:normal !important;
            }
            .sentinel-row-meta .sentinel-pill { margin-left:0 !important; margin-right:6px !important; margin-top:4px !important; }
        }
    </style>
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
    $license  = $audit['license'] ?? ['supported' => false];

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

    // Platform rows can carry two pills: the primary status (security / EOL)
    // plus a solid "Major version behind" pill, so a security flag never hides
    // a major gap - and never implies the security fix needs the major jump.
    $platformBadge = function (array $p, ?string $platform = null) use ($isPatchOnly, $isMajorBehind) {
        $status      = $p['status'] ?? 'unknown';
        $security    = ! empty($p['security_update_available']);
        $current     = $p['current'] ?? $p['version'] ?? null;
        $latest      = $p['latest']  ?? null;
        $outdated    = $latest && $current && version_compare($current, $latest, '<');
        $majorBehind = $outdated && $isMajorBehind($current, $latest, $platform);
        $arrow       = $current . ' → ' . $latest;

        $pills = [];
        if ($security)                  $pills[] = ['text' => 'Security update', 'colour' => '#dc2626'];
        elseif ($status === 'eol')      $pills[] = ['text' => 'End of life',     'colour' => '#dc2626'];
        elseif ($status === 'security') $pills[] = ['text' => 'Security only',   'colour' => '#b45309'];

        // The major gap always leads: it's the solid pill and the bigger job.
        if ($majorBehind) {
            array_unshift($pills, ['text' => 'Major version behind', 'colour' => '#dc2626', 'solid' => true]);
        }

        if ($pills) {
            return ['pills' => $pills, 'detail' => ($security || $majorBehind) ? $arrow : $current];
        }

        if ($outdated) {
            // Patch-only bumps (e.g. 8.4.18 → 8.4.20) get no pill - the
            // version arrow conveys the change without sounding the alarm.
            if ($isPatchOnly($current, $latest)) {
                return ['pills' => [], 'detail' => $arrow];
            }
            // Minor bumps are routine updates and read as "Update available"
            // in blue, matching the ecosystem badges.
            return ['pills' => [['text' => 'Update available', 'colour' => '#3b82f6']], 'detail' => $arrow];
        }
        if (in_array($status, ['ok', 'active'])) return ['pills' => [['text' => 'Up to date', 'colour' => '#10b981']], 'detail' => $current];

        return ['pills' => [['text' => 'Unknown', 'colour' => '#94a3b8']], 'detail' => $current ?? '-'];
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

        // Security issues are the headline, so the (red) pill stands alone.
        if ($totalSec > 0) {
            return ['text' => $vulnsText, 'colour' => '#dc2626', 'detail' => ''];
        }

        // No vulns: updates own the pill (blue), no detail line needed.
        if ($outdated > 0) {
            return ['text' => $updatesText, 'colour' => '#3b82f6', 'detail' => ''];
        }

        return ['text' => 'Up to date', 'colour' => '#10b981', 'detail' => ''];
    };

    // The Statamic license status renders as a colour-coded pill, matching the
    // widget and utility. The covered version range is deliberately not
    // surfaced - it's raw constraint syntax that confuses non-technical readers.
    $licenseBadge = function (array $l) {
        switch ($l['status'] ?? 'unknown') {
            case 'ok':      return ['text' => 'Licensed',     'colour' => '#047857'];
            case 'renewal': return ['text' => 'Renewal due',  'colour' => '#dc2626'];
            case 'invalid': return ['text' => 'Not licensed', 'colour' => '#dc2626'];
            case 'trial':   return ['text' => 'Trial',        'colour' => '#64748b'];
            case 'free':    return ['text' => 'Free edition', 'colour' => '#64748b'];
            default:        return ['text' => 'Unverified',   'colour' => '#64748b'];
        }
    };

    // Licence sits on its own below the packages (rendered after this loop),
    // so it's intentionally left out of $rows here.
    $rows = [
        ['kind' => 'platform', 'label' => 'Statamic', 'description' => 'The CMS that powers your website',           'data' => $statamic],
        ['kind' => 'platform', 'label' => 'Laravel',  'description' => 'The framework Statamic is built on',         'data' => $laravel],
        ['kind' => 'platform', 'label' => 'PHP',      'description' => 'The server-side language that runs everything', 'data' => $php],
        ['kind' => 'eco',      'label' => 'Composer', 'description' => 'Third-party PHP packages your site uses',       'data' => $composer],
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

    // An invalid licence is a real operational problem (raise the red banner);
    // a renewal-due licence is a heads-up handled at the amber tier below.
    $licenseInvalid = ! empty($license['supported']) && ($license['status'] ?? '') === 'invalid';
    $licenseRenewal = ! empty($license['supported']) && ($license['status'] ?? '') === 'renewal';

    $needsAttention = $totalVulns > 0 || $platformEol || $securityUpdate || $licenseInvalid;

    // Mirror the row-level "Major version behind" pill at the banner: any platform
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
    } elseif ($licenseRenewal) {
        $intro        = 'Your Statamic license is due for renewal.';
        $introDetail  = 'The license no longer covers your installed version.';
    } elseif ($platformMajorBehind) {
        $intro        = 'One or more platforms a major version behind.';
    } elseif ($totalOutdated > 0) {
        $intro        = 'Your Statamic website is in good health, routine updates available.';
    } else {
        $intro        = 'Your Statamic website is fully up to date and in good health.';
    }

    // The banner reads like the opening of an email about the Statamic
    // install. The accent colour above still reflects the overall state; the
    // rows below carry the detail. Falls back to the summary line when the
    // audit has no Statamic version, or is out of date without a
    // releases-behind count (scans from before that existed).
    $statamicCurrent = $statamic['current'] ?? null;
    $statamicLatest  = $statamic['latest']  ?? null;
    $statamicBehind  = (int) ($statamic['releases_behind'] ?? 0);
    $introMessage    = null;

    if ($statamicCurrent && $statamicLatest && version_compare($statamicCurrent, $statamicLatest, '<')) {
        if ($statamicBehind > 0) {
            $introMessage = 'Hi, your Statamic installation is running version ' . $statamicCurrent . '. '
                . 'The latest version is ' . $statamicLatest . '. '
                . "That's " . $statamicBehind . ' ' . \Illuminate\Support\Str::plural('version', $statamicBehind) . ' behind.';
        }
    } elseif ($statamicCurrent) {
        $introMessage = 'Hi, your Statamic installation is running the latest version, ' . $statamicCurrent . '.';
    }
@endphp

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f1f5f9;">
<tr>
<td align="center" style="padding:32px 16px;">
<table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="max-width:640px; width:100%; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.1);">
<tr><td>

    {{-- Header --}}
    <div style="background:#0f172a; padding:24px 32px;">
        <div style="font-size:18px; font-weight:700; letter-spacing:-0.02em;">@foreach ($hosts as $i => $h)@if ($i)<span style="color:#cbd5e1;">, </span>@endif<a href="https://{{ $h }}" style="color:#ffffff; text-decoration:none;">{{ $h }}</a>@endforeach</div>
        <div style="font-size:13px; color:#cbd5e1; margin-top:4px;">Statamic Package Status Report &nbsp;·&nbsp; {{ $audit['audited_at'] }}</div>
    </div>

    <div style="padding:28px 32px;">

        {{-- Opening message: plain text, matching the Plan Summary intro --}}
        <div style="margin-bottom:24px;">
            @if ($introMessage)
                <div style="font-size:15px; font-weight:400; color:#0f172a; line-height:1.55;">{{ $introMessage }}</div>
            @else
                <div style="font-size:15px; font-weight:600; color:#0f172a; line-height:1.4;">{{ $intro }}</div>
                @if ($introDetail)
                    <div style="font-size:13px; font-weight:400; color:#475569; line-height:1.4; margin-top:4px;">{{ $introDetail }}</div>
                @endif
            @endif
        </div>

        {{-- Always-visible rows: Statamic / Laravel / PHP / Composer / npm --}}
        @foreach ($rows as $row)
            @php
                $b = $row['kind'] === 'platform' ? $platformBadge($row['data'], $row['label']) : $ecosystemBadge($row['data']);
                $pills = $b['pills'] ?? (! empty($b['text']) ? [$b] : []);
            @endphp
            @if (! $loop->first)
                <div style="border-top:1px solid #e2e8f0; margin:18px 0;"></div>
            @endif
            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; margin-bottom:10px;">
                <tr>
                    <td class="sentinel-row-cell" style="padding:12px 16px; vertical-align:middle;">
                        {{-- Platform rows put the version beside the title; package rows keep
                             their detail (if any) in front of the pills. --}}
                        <div style="font-size:15px; font-weight:600; color:#0f172a;">{{ $row['label'] }}@if ($row['kind'] === 'platform' && ! empty($b['detail']))<span style="font-weight:500; color:#475569; margin-left:8px; font-variant-numeric:tabular-nums;">{{ $b['detail'] }}</span>@endif</div>
                        <div style="font-size:13px; color:#475569; margin-top:3px;">{{ $row['description'] }}</div>
                        <div class="sentinel-row-meta" style="margin-top:8px; font-size:12px; color:#475569; font-variant-numeric:tabular-nums; line-height:1.8;">
                            @if ($row['kind'] !== 'platform' && ! empty($b['detail']))
                                <span style="color:#475569; margin-right:8px;">{{ $b['detail'] }}</span>
                            @endif
                            @foreach ($pills as $pill)
                                <span class="sentinel-pill" style="display:inline-block; margin:2px 6px 2px 0; font-size:10.5px; font-weight:500; padding:1px 7px; border-radius:4px; border:1px solid {{ $pill['colour'] }}; @if (! empty($pill['solid'])) color:#ffffff; background:{{ $pill['colour'] }}; @else color:{{ $pill['colour'] }}; background:#fff; @endif">{{ $pill['text'] }}</span>
                            @endforeach
                        </div>
                    </td>
                </tr>
            </table>
        @endforeach

        {{-- Statamic licence: shown on its own below the packages, since it's
             not a dependency like the rows above. A rule separates the two. --}}
        @if (! empty($license['supported']))
            @php $lb = $licenseBadge($license); @endphp
            <div style="border-top:1px solid #e2e8f0; margin:18px 0;"></div>
            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; margin-bottom:10px;">
                <tr>
                    <td class="sentinel-row-cell" style="padding:12px 16px; vertical-align:middle;">
                        <div style="font-size:15px; font-weight:600; color:#0f172a;">Statamic License Status</div>
                        <div style="font-size:13px; color:#475569; margin-top:3px;">The commercial licence for your CMS</div>
                        <div class="sentinel-row-meta" style="margin-top:8px; font-size:12px; color:#475569; font-variant-numeric:tabular-nums; line-height:1.8;">
                            <span class="sentinel-pill" style="display:inline-block; margin:2px 6px 2px 0; font-size:10.5px; font-weight:500; padding:1px 7px; border-radius:4px; color:{{ $lb['colour'] }}; border:1px solid {{ $lb['colour'] }}; background:#fff;">{{ $lb['text'] }}</span>
                        </div>
                    </td>
                </tr>
            </table>
        @endif

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
    @include('statamic-sentinel::emails._footer', ['lead' => 'This report was generated by'])

</td></tr>
</table>
</td>
</tr>
</table>

</body>
</html>
