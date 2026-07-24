<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no,address=no,email=no,date=no,url=no">
    <title>Statamic Plan Summary</title>
    <style>
        @media only screen and (max-width:480px) {
            .sentinel-row-cell { display:block !important; width:100% !important; }
            .sentinel-row-meta {
                text-align:left !important;
                padding-top:2px !important;
                white-space:normal !important;
            }
            .sentinel-row-meta .sentinel-pill { margin-left:0 !important; }
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
    $plan     = $report['plan'] ?? [];
    $platform = $report['platform'] ?? [];
    $composer = $report['composer'] ?? [];
    $npm      = $report['npm'] ?? [];

    $hasData      = ! empty($report['has_data']);
    $totalUpdates = (int) ($report['total_updates'] ?? 0);
    $totalSec     = (int) ($report['total_security_updates'] ?? 0);

    $planLabel = ! empty($plan['name']) ? $plan['name'] : 'maintenance plan';

    // Intro line - only the plan name, date and update count are bold; the rest
    // is normal weight. Dynamic parts are escaped before being marked up.
    $strong        = fn ($s) => '<strong style="font-weight:600;">' . e($s) . '</strong>';
    $updatesPhrase = $totalUpdates . ' ' . \Illuminate\Support\Str::plural('update', $totalUpdates);

    if (! empty($plan['start'])) {
        $intro = 'Since your ' . $strong($planLabel) . ' started on ' . $strong($plan['start'])
               . ', your site has received ' . $strong($updatesPhrase) . '.';
    } else {
        $intro = 'Your site has received ' . $strong($updatesPhrase) . ' over this period.';
    }

    // Formatted human date range for the header.
    $rangeText = trim(($report['since'] ?? '') . ($report['to'] ? ' to ' . $report['to'] : ''));

    // Platform row helper: this report is purely about how many times each was
    // updated, so it carries only a count - no version numbers.
    $platformRow = function (string $label, array $p) {
        $count = (int) ($p['count'] ?? 0);

        return [
            'label'  => $label,
            'count'  => $count,
            'pill'   => $count > 0 ? $count . ' ' . \Illuminate\Support\Str::plural('update', $count) : 'No change',
            'colour' => $count > 0 ? '#475569' : '#94a3b8',
        ];
    };

    // Ecosystem security sub-line, e.g. "18 were security updates (7 critical, 11 high)".
    $securityLine = function (array $eco) {
        $sec = (int) ($eco['security_updates'] ?? 0);
        if ($sec < 1) {
            return null;
        }

        $line = $sec . ' ' . ($sec === 1 ? 'was a security update' : 'were security updates');

        $bySev   = $eco['security_by_severity'] ?? [];
        $labels  = ['CRITICAL' => 'critical', 'HIGH' => 'high', 'MEDIUM' => 'medium', 'LOW' => 'low'];
        $parts   = [];
        foreach ($labels as $key => $word) {
            $n = (int) ($bySev[$key] ?? 0);
            if ($n > 0) {
                $parts[] = $n . ' ' . $word;
            }
        }
        if (! empty($parts)) {
            $line .= ' (' . implode(', ', $parts) . ')';
        }

        return $line;
    };
@endphp

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f1f5f9;">
<tr>
<td align="center" style="padding:32px 16px;">
<table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="max-width:640px; width:100%; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.1);">
<tr><td>

    {{-- Header --}}
    <div style="background:#0f172a; padding:24px 32px;">
        <div style="font-size:18px; font-weight:700; letter-spacing:-0.02em;">@foreach ($hosts as $i => $h)@if ($i)<span style="color:#cbd5e1;">, </span>@endif<a href="https://{{ $h }}" style="color:#ffffff; text-decoration:none;">{{ $h }}</a>@endforeach</div>
        <div style="font-size:13px; color:#cbd5e1; margin-top:4px;">Plan Summary<span>@if ($rangeText)&nbsp;·&nbsp;{{ $rangeText }}@endif</span></div>
    </div>

    <div style="padding:28px 32px;">

    @if (! $hasData)

        {{-- Empty state --}}
        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:40px 24px; text-align:center;">
            <div style="font-size:15px; font-weight:600; color:#0f172a; margin:0 0 6px 0;">No recorded activity yet</div>
            <div style="font-size:13px; color:#475569; line-height:1.55; max-width:440px; margin:0 auto;">Sentinel records activity when a scan runs after your site changes. Once a few scans are in, this report will show what's been updated.</div>
        </div>

    @else

        {{-- Intro --}}
        <div style="font-size:15px; font-weight:400; color:#0f172a; line-height:1.5; margin-bottom:20px;">{!! $intro !!}</div>

        {{-- Security callout - the strongest value line --}}
        @if ($totalSec > 0)
            <div style="background:#dc26261a; border-left:3px solid #dc2626; padding:12px 16px; border-radius:6px; margin-bottom:24px;">
                <div style="font-size:14px; font-weight:600; color:#0f172a; line-height:1.45;">{{ $totalSec }} of these {{ $totalSec === 1 ? 'was a security update' : 'were security updates' }}, keeping known vulnerabilities patched.</div>
            </div>
        @else
            <div style="height:8px; line-height:8px; font-size:0;">&nbsp;</div>
        @endif

        {{-- Platform rows --}}
        @foreach ([
            ['key' => 'statamic', 'label' => 'Statamic', 'description' => 'The CMS that powers your website'],
            ['key' => 'laravel',  'label' => 'Laravel',  'description' => 'The framework Statamic is built on'],
            ['key' => 'php',      'label' => 'PHP',      'description' => 'The server-side language that runs everything'],
        ] as $row)
            @php $r = $platformRow($row['label'], $platform[$row['key']] ?? []); @endphp
            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; margin-bottom:10px;">
                <tr>
                    <td class="sentinel-row-cell" style="padding:12px 16px; vertical-align:middle;">
                        <div style="font-size:13px; font-weight:600; color:#0f172a;">{{ $r['label'] }}</div>
                        <div style="font-size:12px; color:#475569; margin-top:3px;">{{ $row['description'] }}</div>
                    </td>
                    <td align="right" class="sentinel-row-cell sentinel-row-meta" style="padding:12px 16px; font-size:12px; color:#475569; vertical-align:middle; white-space:nowrap; font-variant-numeric:tabular-nums;">
                        <span class="sentinel-pill" style="display:inline-block; font-size:11px; font-weight:600; padding:2px 8px; border-radius:4px; color:{{ $r['colour'] }}; border:1px solid {{ $r['colour'] }}; background:#fff;">{{ $r['pill'] }}</span>
                    </td>
                </tr>
            </table>
        @endforeach

        {{-- Ecosystem rows --}}
        @foreach ([
            ['data' => $composer, 'label' => 'Composer', 'description' => 'Third-party PHP packages your site uses'],
            ['data' => $npm,      'label' => 'npm',      'description' => 'Third-party JavaScript packages your site uses'],
        ] as $row)
            @php
                $eco     = $row['data'];
                $updates = (int) ($eco['updates'] ?? 0);
                $secLine = $securityLine($eco);
                $partial = (int) ($eco['security_updates'] ?? 0) > (int) ($eco['security_updates_with_severity'] ?? 0);
            @endphp
            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; margin-bottom:10px;">
                <tr>
                    <td class="sentinel-row-cell" style="padding:12px 16px; vertical-align:middle;">
                        <div style="font-size:13px; font-weight:600; color:#0f172a;">{{ $row['label'] }}</div>
                        <div style="font-size:12px; color:#475569; margin-top:3px;">{{ $row['description'] }}</div>
                        @if ($secLine)
                            <div style="font-size:12px; color:#b45309; margin-top:5px; font-weight:500;">{{ $secLine }}@if ($partial) <span style="color:#94a3b8; font-weight:400;">(severity recorded for newer updates)</span>@endif</div>
                        @endif
                    </td>
                    <td align="right" class="sentinel-row-cell sentinel-row-meta" style="padding:12px 16px; font-size:12px; color:#475569; vertical-align:middle; white-space:nowrap; font-variant-numeric:tabular-nums;">
                        <span class="sentinel-pill" style="display:inline-block; font-size:11px; font-weight:600; padding:2px 8px; border-radius:4px; color:{{ $updates > 0 ? '#475569' : '#94a3b8' }}; border:1px solid {{ $updates > 0 ? '#475569' : '#94a3b8' }}; background:#fff;">{{ $updates }} package {{ \Illuminate\Support\Str::plural('update', $updates) }}</span>
                    </td>
                </tr>
            </table>
        @endforeach

        {{-- Plan lifecycle: reassurance + reminder, kept out of the busy top --}}
        @if (! empty($plan['expiry']) || ! empty($plan['show_reminder']))
            <div style="border-top:1px solid #e2e8f0; margin-top:20px; padding-top:16px;">
                @if (! empty($plan['expiry']))
                    <div style="font-size:13px; color:#475569; line-height:1.5;">Your plan runs until {{ $plan['expiry'] }}.</div>
                @endif
                @if (! empty($plan['show_reminder']))
                    <div style="font-size:13px; color:#475569; line-height:1.5; margin-top:4px;">You'll receive a reminder email {{ (int) ($plan['reminder_days'] ?? 30) }} days before your plan ends.</div>
                @endif
            </div>
        @endif

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
