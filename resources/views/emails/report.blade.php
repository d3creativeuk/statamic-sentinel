<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sentinel Report</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-size:14px; color:#1e293b;">

@php
    $statamic = $audit['statamic'];
    $laravel  = $audit['laravel'];
    $php      = $audit['php'];
    $composer = $audit['composer'];
    $npm      = $audit['npm'];

    $statusColours = [
        'ok'         => '#10b981',
        'active'     => '#10b981',
        'outdated'   => '#f59e0b',
        'security'   => '#f59e0b',
        'vulnerable' => '#ef4444',
        'eol'        => '#ef4444',
        'error'      => '#ef4444',
    ];
    $dotColour = fn($s) => $statusColours[$s] ?? '#94a3b8';

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
        ($statamic['status'] === 'ok') &&
        in_array($laravel['status'], ['ok', 'active', 'security']) &&
        in_array($php['status'], ['ok', 'active', 'security']) &&
        in_array($composer['status'], ['ok', 'unavailable']) &&
        in_array($npm['status'], ['ok', 'unavailable']);

    if ($criticalCount > 0) {
        $statusAccent  = '#ef4444';
        $statusLine    = $criticalCount . ' critical ' . \Illuminate\Support\Str::plural('vulnerability', $criticalCount) . ' require attention.';
        $statusPrefix  = '⚠';
    } elseif ($overallOk && $totalVulns === 0 && $totalOutdated === 0) {
        $statusAccent  = '#10b981';
        $statusLine    = 'No issues detected on ' . request()->getHost() . '.';
        $statusPrefix  = '✓';
    } else {
        $statusAccent  = '#f59e0b';
        $bits = [];
        if ($totalVulns > 0)    $bits[] = $totalVulns . ' ' . \Illuminate\Support\Str::plural('vulnerability', $totalVulns) . ' found';
        if ($totalOutdated > 0) $bits[] = $totalOutdated . ' ' . \Illuminate\Support\Str::plural('package', $totalOutdated) . ' need updates';
        $statusLine    = implode(', ', $bits) . '.';
        $statusPrefix  = '•';
    }
@endphp

<div style="max-width:600px; margin:32px auto; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.1);">

    {{-- Header --}}
    <div style="background:#0f172a; padding:24px 32px;">
        <div style="font-size:18px; font-weight:700; color:#ffffff; letter-spacing:-0.02em;">Sentinel Report</div>
        <div style="font-size:13px; color:#94a3b8; margin-top:4px;">{{ request()->getHost() }} &nbsp;·&nbsp; Last scanned: {{ $audit['audited_at'] }}</div>
    </div>

    <div style="padding:28px 32px;">

        {{-- Status line --}}
        <div style="background:{{ $statusAccent }}1a; border-left:3px solid {{ $statusAccent }}; padding:14px 16px; border-radius:6px; margin-bottom:20px;">
            <span style="font-size:14px; font-weight:600; color:{{ $statusAccent }};">{{ $statusPrefix }}</span>
            <span style="font-size:14px; font-weight:500; color:#0f172a; margin-left:6px;">{{ $statusLine }}</span>
        </div>

        {{-- Version pills --}}
        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; margin-bottom:24px;">
            @foreach ([
                ['label' => 'Statamic', 'version' => $statamic['current'], 'status' => $statamic['status']],
                ['label' => 'Laravel',  'version' => $laravel['version'],  'status' => $laravel['status']],
                ['label' => 'PHP',      'version' => $php['version'],      'status' => $php['status']],
            ] as $row)
                <tr>
                    <td style="padding:8px 12px; {{ ! $loop->last ? 'border-bottom:1px solid #f1f5f9;' : '' }} width:120px; font-weight:600; font-size:13px;">{{ $row['label'] }}</td>
                    <td style="padding:8px 12px; {{ ! $loop->last ? 'border-bottom:1px solid #f1f5f9;' : '' }} font-size:13px;">
                        {{ $row['version'] }}
                        <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:{{ $dotColour($row['status']) }}; margin-left:6px; vertical-align:middle;"></span>
                    </td>
                </tr>
            @endforeach
        </table>

        {{-- CTA --}}
        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
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
        <div style="font-size:12px; color:#94a3b8;">
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
