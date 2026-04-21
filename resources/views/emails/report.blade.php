<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sentinel Report</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-size:14px; color:#1e293b;">

@php
    $statusColours = [
        'ok'         => '#10b981',
        'active'     => '#10b981',
        'outdated'   => '#f59e0b',
        'security'   => '#f59e0b',
        'vulnerable' => '#ef4444',
        'eol'        => '#ef4444',
        'error'      => '#ef4444',
    ];
    $severityColours = [
        'CRITICAL' => '#ef4444',
        'HIGH'     => '#f97316',
        'MEDIUM'   => '#f59e0b',
        'LOW'      => '#3b82f6',
        'UNKNOWN'  => '#94a3b8',
    ];
    $statusColour   = fn($s) => $statusColours[$s]   ?? '#94a3b8';
    $severityColour = fn($s) => $severityColours[$s] ?? '#94a3b8';
    $dot            = fn($s) => '<span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:' . ($statusColours[$s] ?? '#94a3b8') . '; margin-left:6px; vertical-align:middle;"></span>';

    $s = $audit['statamic'];
    $l = $audit['laravel'];
    $p = $audit['php'];
    $c = $audit['composer'];
    $n = $audit['npm'];
@endphp

<div style="max-width:600px; margin:32px auto; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.1);">

    {{-- Header --}}
    <div style="background:#0f172a; padding:24px 32px;">
        <div style="font-size:18px; font-weight:700; color:#ffffff; letter-spacing:-0.02em;">Sentinel Report</div>
        <div style="font-size:13px; color:#94a3b8; margin-top:4px;">{{ request()->getHost() }} &nbsp;·&nbsp; Last scanned: {{ $audit['audited_at'] }}</div>
    </div>

    <div style="padding:28px 32px;">

        {{-- Version summary --}}
        <div style="margin-bottom:24px;">
            <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; color:#94a3b8; margin-bottom:12px;">Software Versions</div>

            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                {{-- Statamic --}}
                <tr>
                    <td style="padding:10px 12px; border-bottom:1px solid #f1f5f9; width:120px; font-weight:600; font-size:13px;">Statamic</td>
                    <td style="padding:10px 12px; border-bottom:1px solid #f1f5f9; font-size:13px;">
                        {{ $s['current'] }}
                        {!! $dot($s['status']) !!}
                    </td>
                    <td style="padding:10px 12px; border-bottom:1px solid #f1f5f9; font-size:12px; color:#64748b; text-align:right;">
                        @if($s['status'] === 'outdated') Latest: {{ $s['latest'] }}
                        @elseif($s['status'] === 'ok') Up to date
                        @else Unknown
                        @endif
                    </td>
                </tr>
                {{-- Laravel --}}
                <tr>
                    <td style="padding:10px 12px; border-bottom:1px solid #f1f5f9; font-weight:600; font-size:13px;">Laravel</td>
                    <td style="padding:10px 12px; border-bottom:1px solid #f1f5f9; font-size:13px;">
                        {{ $l['version'] }}
                        {!! $dot($l['status']) !!}
                    </td>
                    <td style="padding:10px 12px; border-bottom:1px solid #f1f5f9; font-size:12px; color:#64748b; text-align:right;">
                        @if(!$l['is_latest'] && $l['latest']) Latest: {{ $l['latest'] }}
                        @else {{ $l['label'] }}
                        @endif
                    </td>
                </tr>
                {{-- PHP --}}
                <tr>
                    <td style="padding:10px 12px; font-weight:600; font-size:13px;">PHP</td>
                    <td style="padding:10px 12px; font-size:13px;">
                        {{ $p['version'] }}
                        {!! $dot($p['status']) !!}
                    </td>
                    <td style="padding:10px 12px; font-size:12px; color:#64748b; text-align:right;">{{ $p['label'] }}</td>
                </tr>
            </table>
        </div>

        {{-- Package checks --}}
        @foreach ([['label' => 'Composer Packages', 'data' => $c], ['label' => 'npm Packages', 'data' => $n]] as $row)
        @php $d = $row['data']; @endphp
        <div style="margin-bottom:20px;">
            <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; color:#94a3b8; margin-bottom:10px;">
                {{ $row['label'] }}
                @if($d['status'] !== 'unavailable' && isset($d['total_packages']))
                    <span style="font-weight:400; text-transform:none; letter-spacing:0; color:#cbd5e1;">&nbsp;— {{ $d['total_packages'] }} packages scanned</span>
                @endif
            </div>

            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px;">

                {{-- Security --}}
                <div style="font-size:12px; font-weight:600; color:#475569; margin-bottom:8px;">Security</div>
                @if($d['status'] === 'unavailable')
                    <p style="margin:0 0 4px; font-size:13px; color:#94a3b8;">Lock file not found — unable to scan.</p>
                @elseif($d['status'] === 'ok' || ($d['total_vulns'] ?? 0) === 0)
                    <p style="margin:0 0 4px; font-size:13px; color:#10b981; font-weight:500;">✓ No known vulnerabilities</p>
                @else
                    <div style="margin-bottom:4px;">
                        @foreach(['CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'UNKNOWN'] as $sev)
                        @php $count = $d['counts'][$sev] ?? 0; @endphp
                        @if($count > 0)
                            <span style="display:inline-block; font-size:11px; font-weight:700; color:#fff; background:{{ $severityColour($sev) }}; padding:2px 8px; border-radius:4px; margin-right:4px; margin-bottom:4px;">{{ $count }} {{ $sev }}</span>
                        @endif
                        @endforeach
                    </div>
                @endif

                {{-- Updates --}}
                @if(isset($d['outdated']))
                    <div style="border-top:1px solid #e2e8f0; margin-top:10px; padding-top:10px;">
                        <div style="font-size:12px; font-weight:600; color:#475569; margin-bottom:8px;">Updates Available</div>
                        @if($d['outdated']['total'] > 0)
                            <span style="display:inline-block; font-size:11px; font-weight:600; color:#475569; background:#e2e8f0; padding:2px 8px; border-radius:4px;">
                                {{ $d['outdated']['total'] }} {{ \Illuminate\Support\Str::plural('package', $d['outdated']['total']) }} out of date
                            </span>
                        @else
                            <p style="margin:0; font-size:13px; color:#10b981; font-weight:500;">✓ All packages up to date</p>
                        @endif
                    </div>
                @endif

            </div>
        </div>
        @endforeach

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
