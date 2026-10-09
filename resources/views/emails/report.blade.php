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

    // How big the jump to the latest version is. PHP's X.Y branches count as
    // major (see $isMajorBehind); otherwise semver: a changed minor number
    // is minor, anything else a patch.
    $updateTier = function ($current, $latest, $platform = null) use ($isMajorBehind) {
        if ($isMajorBehind($current, $latest, $platform)) return 'major';
        $c = explode('.', (string) $current);
        $l = explode('.', (string) $latest);
        return ($c[1] ?? null) !== ($l[1] ?? null) ? 'minor' : 'patch';
    };

    // Patch is routine (muted blue), minor a feature release (blue), major
    // the bigger job (red). Each passes AA contrast with white text.
    $tierPills = [
        'patch' => ['text' => 'Patch', 'colour' => '#4f73b8'],
        'minor' => ['text' => 'Minor', 'colour' => '#2563eb'],
        'major' => ['text' => 'Major', 'colour' => '#dc2626'],
    ];

    // Platform rows: a security update trumps everything and stands alone.
    // Otherwise any lifecycle pill (end of life / security only) first, then
    // the update tier with "update available" beside it.
    $platformBadge = function (array $p, ?string $platform = null) use ($updateTier, $tierPills) {
        $status      = $p['status'] ?? 'unknown';
        $security    = ! empty($p['security_update_available']);
        $current     = $p['current'] ?? $p['version'] ?? null;
        $latest      = $p['latest']  ?? null;
        $outdated    = $latest && $current && version_compare($current, $latest, '<');
        $arrow       = $current . ' → ' . $latest;

        if ($security) {
            return ['pills' => [['text' => 'Security update', 'colour' => '#dc2626']], 'detail' => $outdated ? $arrow : $current];
        }

        $pills = [];
        if ($status === 'eol')          $pills[] = ['text' => 'End of life',     'colour' => '#dc2626'];
        elseif ($status === 'security') $pills[] = ['text' => 'Security only',   'colour' => '#b45309'];

        if ($outdated) {
            $pills[] = $tierPills[$updateTier($current, $latest, $platform)];

            return ['pills' => $pills, 'note' => 'update available', 'detail' => $arrow];
        }

        if ($pills) {
            return ['pills' => $pills, 'detail' => $current];
        }

        if (in_array($status, ['ok', 'active'])) return ['pills' => [['text' => 'Up to date', 'colour' => '#047857']], 'detail' => $current];

        return ['pills' => [['text' => 'Unknown', 'colour' => '#64748b']], 'detail' => $current ?? '-'];
    };

    $ecosystemBadge = function (array $eco) {
        $status     = $eco['status']     ?? 'unknown';
        $vulns      = (int) ($eco['total_vulns']                            ?? 0);
        $outdated   = (int) ($eco['outdated']['total']                      ?? 0);
        // Vendor-flagged security releases that don't yet have an OSV advisory.
        // Counted toward the security pill so the email matches the CP badge.
        $vendorOnly = (int) ($eco['outdated']['vendor_security_updates_total'] ?? 0);
        $totalSec   = $vulns + $vendorOnly;

        if ($status === 'unavailable') return ['text' => 'Not found',    'colour' => '#64748b', 'detail' => 'Lock file not found'];
        if ($status === 'error')       return ['text' => 'Check failed', 'colour' => '#dc2626', 'detail' => ! empty($eco['lock_unreadable']) ? 'Lock file could not be read' : 'Could not reach the registry'];

        $updatesText = $outdated . ' ' . \Illuminate\Support\Str::plural('update', $outdated) . ' available';
        $updatesFailed = ! empty($eco['outdated']['error']);
        $vulnsText   = $totalSec . ' security ' . \Illuminate\Support\Str::plural('issue', $totalSec);

        // Security issues are the headline, so the (red) pill stands alone.
        if ($totalSec > 0) {
            return ['text' => $vulnsText, 'colour' => '#dc2626', 'detail' => ''];
        }

        // A registry outage isn't "up to date".
        if ($updatesFailed) {
            return ['text' => 'Update check failed', 'colour' => '#dc2626', 'detail' => 'Could not reach the package registry'];
        }

        // No vulns: updates own the pill (blue), no detail line needed.
        if ($outdated > 0) {
            return ['text' => $updatesText, 'colour' => '#2563eb', 'detail' => ''];
        }

        return ['text' => 'Up to date', 'colour' => '#047857', 'detail' => ''];
    };

    // The Statamic licence status renders as a colour-coded pill, matching the
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
        ['kind' => 'platform', 'label' => 'PHP',      'description' => 'The language Laravel is built on', 'data' => $php],
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

    // Mirror the row-level "Major" pill at the banner: any platform
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
    // Whether the summary below is worth saying alongside the greeting:
    // anything other than routine updates or all clear.
    $introUrgent = true;
    if ($needsAttention) {
        // Say exactly what needs attention: a security fix in particular
        // must always be named in the opening, never left to the rows.
        $_names = fn (array $list) => count($list) > 1
            ? implode(', ', array_slice($list, 0, -1)) . ' and ' . end($list)
            : ($list[0] ?? '');
        $_parts = [];
        $_secure = array_keys(array_filter(['Statamic' => $statamic['security_update_available'] ?? false, 'Laravel' => $laravel['security_update_available'] ?? false]));
        if ($_secure) {
            $_parts[] = (count($_secure) > 1 ? 'Security updates are' : 'A security update is') . ' available for ' . $_names($_secure) . '.';
        }
        if ($totalVulns > 0) {
            $_parts[] = $totalVulns . ' known ' . \Illuminate\Support\Str::plural('vulnerability', $totalVulns) . ' ' . ($totalVulns === 1 ? 'was' : 'were') . ' found in your packages.';
        }
        $_eol = [];
        foreach (['Statamic' => $statamic, 'Laravel' => $laravel, 'PHP' => $php] as $_name => $_p) {
            if (($_p['status'] ?? null) === 'eol') {
                // The branch that's ended: PHP's are X.Y, the others' the major.
                $_v = explode('.', (string) ($_p['current'] ?? $_p['version'] ?? ''));
                $_eol[] = trim($_name . ' ' . ($_name === 'PHP' ? implode('.', array_slice($_v, 0, 2)) : ($_v[0] ?? '')));
            }
        }
        if ($_eol) {
            $_parts[] = $_names($_eol) . ' ' . (count($_eol) > 1 ? 'have' : 'has') . ' reached end of life.';
        }
        if ($licenseInvalid) {
            $_parts[] = 'Your Statamic licence is not valid.';
        }
        $intro        = 'Your Statamic website needs attention.';
        $introDetail  = implode(' ', $_parts) ?: 'Security or platform issues were found.';
    } elseif ($licenseRenewal) {
        $intro        = 'Your Statamic licence is due for renewal.';
        $introDetail  = 'The licence no longer covers your installed version.';
    } elseif ($platformMajorBehind) {
        $intro        = 'One or more platforms are a major version behind.';
    } elseif (! empty($composer['outdated']['error']) || ! empty($npm['outdated']['error'])) {
        $intro        = "Sentinel couldn't finish checking for updates.";
        $introDetail  = 'A package registry did not respond, so some updates may not be listed.';
    } elseif ($totalOutdated > 0) {
        $intro        = 'Your Statamic website is in good health, routine updates available.';
        $introUrgent  = false;
    } else {
        $intro        = 'Your Statamic website is fully up to date and in good health.';
        $introUrgent  = false;
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
            // Patch releases are bug fixes: say so, and that they can wait,
            // unless one of them is a security fix.
            $statamicPatch = $updateTier($statamicCurrent, $statamicLatest, 'Statamic') === 'patch';
            $introMessage = 'Hi, your Statamic installation is running version ' . $statamicCurrent . '. '
                . 'The latest version is ' . $statamicLatest . '. '
                . "That's " . $statamicBehind . ' ' . ($statamicPatch ? 'patch ' : '') . \Illuminate\Support\Str::plural('version', $statamicBehind) . ' behind'
                . (! empty($statamic['security_update_available'])
                    ? ($statamicBehind === 1 ? ', and it includes a security fix.' : ', including a security fix.')
                    : ($statamicPatch ? ', not urgent.' : '.'));
        }
    } elseif ($statamicCurrent && $statamicLatest) {
        // Only when the latest version is known: an unreachable registry
        // leaves it null, which isn't the same as being up to date.
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
        <div style="font-size:13px; color:#cbd5e1; margin-top:4px;">Statamic Package Status Report</div>
        <div style="font-size:13px; color:#cbd5e1; margin-top:2px;">{{ $audit['audited_at'] }}</div>
    </div>

    <div style="padding:28px 32px;">

        {{-- Opening message: plain text, matching the Plan Summary intro --}}
        <div style="margin-bottom:24px;">
            {{-- The greeting is only about Statamic, so anything urgent
                 elsewhere (vulnerabilities, end of life, licence) follows it in
                 its own paragraph rather than being hidden behind an all-clear.
                 Without a greeting the summary stands alone. --}}
            @if ($introMessage)
                <div style="font-size:15px; font-weight:400; color:#0f172a; line-height:1.55;">{{ $introMessage }}</div>
            @endif
            @if (! $introMessage || $introUrgent)
                <div style="font-size:15px; font-weight:400; color:#0f172a; line-height:1.55; {{ $introMessage ? 'margin-top:12px;' : '' }}"><strong style="font-weight:600;">{{ $intro }}</strong>@if ($introDetail) {{ $introDetail }}@endif</div>
            @endif

            {{-- Context for the rows below, for readers who don't know why a site has several versions. --}}
            <div style="font-size:13px; font-weight:400; color:#475569; line-height:1.55; margin-top:12px;"><strong style="font-weight:600; color:#0f172a;">Did you know?</strong> Your website isn't a single piece of software. Statamic runs on Laravel, which runs on PHP, and it also uses dozens of smaller packages for things like forms, images and search. Each receives its own updates and security fixes.</div>
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
                        <div style="font-size:15px; font-weight:600; color:#0f172a;">@if ($row['label'] === 'Statamic' && ($statamicIcon = \D3Creative\Sentinel\Support\StatamicIcon::src($message ?? null)))<img src="{{ $statamicIcon }}" width="16" height="16" alt="" style="display:inline-block; width:16px; height:16px; vertical-align:-2px; margin-right:7px; border:0;">@endif{{ $row['label'] }}@if ($row['kind'] === 'platform' && ! empty($b['detail']))<span style="font-weight:500; color:#475569; margin-left:8px; font-variant-numeric:tabular-nums;">{{ $b['detail'] }}</span>@endif</div>
                        <div style="font-size:13px; color:#475569; margin-top:3px;">{{ $row['description'] }}</div>
                        <div class="sentinel-row-meta" style="margin-top:8px; font-size:12px; color:#475569; font-variant-numeric:tabular-nums; line-height:1.8;">
                            @if ($row['kind'] !== 'platform' && ! empty($b['detail']))
                                <span style="color:#475569; margin-right:8px;">{{ $b['detail'] }}</span>
                            @endif
                            @foreach ($pills as $pill)
                                <span class="sentinel-pill" style="display:inline-block; text-transform:uppercase; letter-spacing:0.04em; margin:2px 6px 2px 0; font-size:10.5px; font-weight:500; padding:1px 7px; border-radius:4px; border:1px solid {{ $pill['colour'] }}; color:#fff; background:{{ $pill['colour'] }};">{{ $pill['text'] }}</span>
                            @endforeach
                            @if (! empty($b['note']))
                                <span style="color:#64748b;">{{ $b['note'] }}</span>
                            @endif
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
                        <div style="font-size:15px; font-weight:600; color:#0f172a;">Statamic Licence Status</div>
                        <div style="font-size:13px; color:#475569; margin-top:3px;">The commercial licence for your CMS</div>
                        <div class="sentinel-row-meta" style="margin-top:8px; font-size:12px; color:#475569; font-variant-numeric:tabular-nums; line-height:1.8;">
                            <span class="sentinel-pill" style="display:inline-block; text-transform:uppercase; letter-spacing:0.04em; margin:2px 6px 2px 0; font-size:10.5px; font-weight:500; padding:1px 7px; border-radius:4px; color:#fff; border:1px solid {{ $lb['colour'] }}; background:{{ $lb['colour'] }};">{{ $lb['text'] }}</span>
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
    @include('statamic-sentinel::emails._footer', ['lead' => 'This report was generated by', 'campaign' => 'status-report'])

</td></tr>
</table>
</td>
</tr>
</table>

</body>
</html>
