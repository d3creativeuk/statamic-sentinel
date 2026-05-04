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

    $criticalCount =
        ($composer['counts']['CRITICAL'] ?? 0) +
        ($npm['counts']['CRITICAL'] ?? 0);

    $totalVulns =
        ($composer['total_vulns'] ?? 0) +
        ($npm['total_vulns'] ?? 0);

    $totalOutdated =
        ($composer['outdated']['total'] ?? 0) +
        ($npm['outdated']['total'] ?? 0);

    $overallOk =
        in_array($statamic['status'], ['ok']) &&
        in_array($laravel['status'],  ['ok', 'active', 'security']) &&
        in_array($php['status'],      ['ok', 'active', 'security']) &&
        in_array($composer['status'], ['ok', 'unavailable']) &&
        in_array($npm['status'],      ['ok', 'unavailable']);

    if ($criticalCount > 0) {
        $statusAccent = '#ef4444';
        $statusLine   = $criticalCount . ' critical ' . \Illuminate\Support\Str::plural('vulnerability', $criticalCount) . ' require attention.';
    } elseif ($overallOk && $totalVulns === 0 && $totalOutdated === 0) {
        $statusAccent = '#10b981';
        $statusLine   = 'No issues detected on ' . $host . '.';
    } else {
        $statusAccent = '#f59e0b';
        $bits = [];
        if ($totalVulns > 0)    $bits[] = $totalVulns    . ' ' . \Illuminate\Support\Str::plural('vulnerability', $totalVulns) . ' found';
        if ($totalOutdated > 0) $bits[] = $totalOutdated . ' ' . \Illuminate\Support\Str::plural('package', $totalOutdated)    . ' need updates';
        $statusLine   = implode(', ', $bits) . '.';
    }

    // Build the badge for each row: text, foreground colour, and optional
    // detail line shown to the left of the badge.
    $platformBadge = function (array $p) {
        $status   = $p['status'] ?? 'unknown';
        $security = ! empty($p['security_update_available']);
        $current  = $p['current'] ?? $p['version'] ?? null;
        $latest   = $p['latest']  ?? null;
        $outdated = $latest && $current && version_compare($current, $latest, '<');

        if ($security)               return ['text' => 'Security update', 'colour' => '#dc2626', 'detail' => $current . ' → ' . $latest];
        if ($status === 'eol')       return ['text' => 'End of life',     'colour' => '#dc2626', 'detail' => $current];
        if ($status === 'security')  return ['text' => 'Security only',   'colour' => '#b45309', 'detail' => $current];
        if ($outdated)               return ['text' => 'Outdated',        'colour' => '#b45309', 'detail' => $current . ' → ' . $latest];
        if (in_array($status, ['ok', 'active'])) return ['text' => 'Up to date', 'colour' => '#10b981', 'detail' => $current];

        return ['text' => 'Unknown', 'colour' => '#94a3b8', 'detail' => $current ?? '—'];
    };

    $ecosystemBadge = function (array $eco) {
        $status     = $eco['status']     ?? 'unknown';
        $vulns      = (int) ($eco['total_vulns']                  ?? 0);
        $outdated   = (int) ($eco['outdated']['total']            ?? 0);
        $secUpdates = (int) ($eco['outdated']['security_updates_total'] ?? 0);

        if ($status === 'unavailable') return ['text' => 'Not found',    'colour' => '#94a3b8', 'detail' => 'Lock file not found',                  'lock' => false];
        if ($status === 'error')       return ['text' => 'Check failed', 'colour' => '#dc2626', 'detail' => 'Could not reach the registry',         'lock' => false];

        if ($vulns > 0) {
            $det = $vulns . ' security ' . \Illuminate\Support\Str::plural('issue', $vulns);
            if ($outdated > 0) $det .= ', ' . $outdated . ' ' . \Illuminate\Support\Str::plural('update', $outdated) . ' available';
            return ['text' => $vulns . ' ' . \Illuminate\Support\Str::plural('issue', $vulns), 'colour' => '#dc2626', 'detail' => $det, 'lock' => $secUpdates > 0];
        }

        if ($outdated > 0) {
            $det = $outdated . ' ' . \Illuminate\Support\Str::plural('update', $outdated) . ' available';
            return ['text' => $outdated . ' ' . \Illuminate\Support\Str::plural('update', $outdated), 'colour' => '#b45309', 'detail' => $det, 'lock' => $secUpdates > 0];
        }

        return ['text' => 'Up to date', 'colour' => '#10b981', 'detail' => 'All packages up to date', 'lock' => false];
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
        <div style="font-size:18px; font-weight:700; color:#ffffff; letter-spacing:-0.02em;">Statamic Package Status Report</div>
        <div style="font-size:13px; color:#94a3b8; margin-top:4px;">{{ $host }} &nbsp;·&nbsp; Last scanned: {{ $audit['audited_at'] }}</div>
    </div>

    <div style="padding:28px 32px;">

        {{-- Status banner --}}
        <div style="background:{{ $statusAccent }}1a; border-left:3px solid {{ $statusAccent }}; padding:14px 16px; border-radius:6px; margin-bottom:24px;">
            <span style="font-size:14px; font-weight:500; color:#0f172a;">{{ $statusLine }}</span>
        </div>

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
                        <span style="color:#475569;">{{ $b['detail'] }}</span>
                        @if (! empty($b['lock']))
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 20 20" fill="#dc2626" style="flex-shrink:0; vertical-align:middle; margin-left:6px;"><title>Includes security update</title><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1zm3 8V5.5a3 3 0 1 0-6 0V9h6z" clip-rule="evenodd"></path></svg>
                        @endif
                        <span style="display:inline-block; margin-left:10px; font-size:11px; font-weight:600; padding:2px 8px; border-radius:4px; color:{{ $b['colour'] }}; border:1px solid {{ $b['colour'] }}; background:#fff;">{{ $b['text'] }}</span>
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
