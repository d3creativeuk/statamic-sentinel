<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statamic Package Status Report</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-size:14px; color:#1e293b;">

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

    $platformBadge = function (array $p) use ($isPatchOnly) {
        $status   = $p['status'] ?? 'unknown';
        $security = ! empty($p['security_update_available']);
        $current  = $p['current'] ?? $p['version'] ?? null;
        $latest   = $p['latest']  ?? null;
        $outdated = $latest && $current && version_compare($current, $latest, '<');

        if ($security)               return ['text' => 'Security update', 'colour' => '#dc2626', 'detail' => $current . ' → ' . $latest];
        if ($status === 'eol')       return ['text' => 'End of life',     'colour' => '#dc2626', 'detail' => $current];
        if ($status === 'security')  return ['text' => 'Security only',   'colour' => '#b45309', 'detail' => $current];
        if ($outdated) {
            // Patch-only bumps (e.g. 8.4.18 → 8.4.20) get no pill — the
            // version arrow conveys the change without sounding the alarm.
            if ($isPatchOnly($current, $latest)) {
                return ['text' => null, 'colour' => null, 'detail' => $current . ' → ' . $latest];
            }
            return ['text' => 'Outdated', 'colour' => '#b45309', 'detail' => $current . ' → ' . $latest];
        }
        if (in_array($status, ['ok', 'active'])) return ['text' => 'Up to date', 'colour' => '#10b981', 'detail' => $current];

        return ['text' => 'Unknown', 'colour' => '#94a3b8', 'detail' => $current ?? '—'];
    };

    $ecosystemBadge = function (array $eco) {
        $status   = $eco['status']     ?? 'unknown';
        $vulns    = (int) ($eco['total_vulns']         ?? 0);
        $outdated = (int) ($eco['outdated']['total']   ?? 0);

        if ($status === 'unavailable') return ['text' => 'Not found',    'colour' => '#94a3b8', 'detail' => 'Lock file not found'];
        if ($status === 'error')       return ['text' => 'Check failed', 'colour' => '#dc2626', 'detail' => 'Could not reach the registry'];

        $updatesText = $outdated . ' ' . \Illuminate\Support\Str::plural('update', $outdated) . ' available';
        $vulnsText   = $vulns    . ' security ' . \Illuminate\Support\Str::plural('issue', $vulns);

        // Vulns take the (red) pill when present; updates fall back to the
        // detail line so the row never duplicates itself.
        if ($vulns > 0) {
            return ['text' => $vulnsText, 'colour' => '#dc2626', 'detail' => $outdated > 0 ? $updatesText : ''];
        }

        // No vulns: updates own the pill (blue), no detail line needed.
        if ($outdated > 0) {
            return ['text' => $updatesText, 'colour' => '#3b82f6', 'detail' => ''];
        }

        return ['text' => 'Up to date', 'colour' => '#10b981', 'detail' => ''];
    };

    $rows = [
        ['kind' => 'platform', 'label' => 'Statamic', 'data' => $statamic],
        ['kind' => 'platform', 'label' => 'Laravel',  'data' => $laravel],
        ['kind' => 'platform', 'label' => 'PHP',      'data' => $php],
        ['kind' => 'eco',      'label' => 'Composer', 'data' => $composer],
        ['kind' => 'eco',      'label' => 'npm',      'data' => $npm],
    ];
@endphp

<div style="max-width:640px; margin:32px auto; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.1);">

    {{-- Header --}}
    <div style="background:#0f172a; padding:24px 32px;">
        <div style="font-size:18px; font-weight:700; color:#ffffff; letter-spacing:-0.02em;">{{ $host }}</div>
        <div style="font-size:13px; color:#cbd5e1; margin-top:4px;">Statamic Package Status Report &nbsp;·&nbsp; {{ $audit['audited_at'] }}</div>
    </div>

    <div style="padding:28px 32px;">

        {{-- Always-visible rows: Statamic / Laravel / PHP / Composer / npm --}}
        @foreach ($rows as $row)
            @php
                $b = $row['kind'] === 'platform' ? $platformBadge($row['data']) : $ecosystemBadge($row['data']);
            @endphp
            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; margin-bottom:10px;">
                <tr>
                    <td style="padding:12px 16px; font-size:13px; font-weight:600; color:#0f172a; vertical-align:middle;">
                        {{ $row['label'] }}
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

        {{-- CTA --}}
        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; margin-top:14px;">
            <tr>
                <td align="center" style="padding:4px 0 8px;">
                    <a href="{{ $utility_url }}"
                       style="display:inline-block; background:#0f172a; color:#ffffff; font-size:14px; font-weight:600; text-decoration:none; padding:12px 28px; border-radius:8px;">
                        View full report →
                    </a>
                </td>
            </tr>
        </table>

    </div>

    {{-- Footer --}}
    <div style="background:#f8fafc; border-top:1px solid #e2e8f0; padding:20px 32px; display:flex; align-items:center; justify-content:space-between;">
        <div style="font-size:12px; color:#64748b;">
            This report was generated by the D3 Creative Sentinel addon.
        </div>
        <div>
            <a href="https://d3creative.uk/services/support-and-maintenance"
               style="font-size:12px; font-weight:600; color:#0f172a; text-decoration:none;">
                d3creative.uk ↗
            </a>
        </div>
    </div>

</div>

</body>
</html>
