<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statamic Package Update Report</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-size:14px; color:#1e293b;">

@php
    $platform = $report['platform'];
    $composer = $report['composer'];
    $npm      = $report['npm'];
    $vulns    = $report['vulns'];

    $platformChanged     = array_filter($platform, fn($p) => $p['changed']);
    $platformChangeCount = count($platformChanged);

    $composerChanges = count($composer['updated']) + count($composer['added']) + count($composer['removed']);
    $npmChanges      = count($npm['updated'])      + count($npm['added'])      + count($npm['removed']);

    $vulnsResolved = $vulns['composer_resolved']   + $vulns['npm_resolved'];
    $vulnsIntro    = $vulns['composer_introduced'] + $vulns['npm_introduced'];

    $bits = [];
    if ($platformChangeCount > 0) $bits[] = $platformChangeCount . ' platform ' . \Illuminate\Support\Str::plural('upgrade', $platformChangeCount);
    if ($composerChanges > 0)     $bits[] = $composerChanges . ' Composer ' . \Illuminate\Support\Str::plural('change', $composerChanges);
    if ($npmChanges > 0)          $bits[] = $npmChanges . ' npm ' . \Illuminate\Support\Str::plural('change', $npmChanges);
    if ($vulnsResolved > 0)       $bits[] = $vulnsResolved . ' ' . \Illuminate\Support\Str::plural('vulnerability', $vulnsResolved) . ' resolved';
    if ($vulnsIntro > 0)          $bits[] = $vulnsIntro . ' new ' . \Illuminate\Support\Str::plural('vulnerability', $vulnsIntro);

    $hasAnyChange = ! empty($bits);
    $statusLine   = $hasAnyChange ? implode(', ', $bits) . '.' : 'No changes detected since the previous snapshot.';
    $statusAccent = $vulnsIntro > 0 ? '#ef4444' : ($hasAnyChange ? '#10b981' : '#94a3b8');

    if ($vulnsIntro > 0) {
        $intro = 'Your Statamic website has been updated, but new security issues need attention.';
    } elseif ($hasAnyChange) {
        $intro = 'Your Statamic website has been updated and is in good health.';
    } else {
        $intro = 'No updates have been applied to your Statamic website since the last report.';
    }

    $sentDate = now()->format('j M Y, H:i');

    // Inline helpers - keep email template self-contained
    $platformRow = function (string $label, array $p) {
        $changed = $p['changed'] ?? false;
        $from    = $p['from'] ?? null;
        $to      = $p['to'] ?? null;

        return [
            'label'   => $label,
            'badge'   => $changed ? 'Updated' : 'No change',
            'colour'  => $changed ? '#10b981' : '#94a3b8',
            'detail'  => $changed
                ? ($from . ' → ' . $to)
                : ($to ?? $from ?? '-'),
            'changed' => $changed,
        ];
    };

    $ecosystemSummary = function (array $eco) {
        $u = count($eco['updated']);
        $a = count($eco['added']);
        $r = count($eco['removed']);

        if ($u + $a + $r === 0) return ['badge' => 'No change', 'colour' => '#94a3b8'];

        $parts = [];
        if ($u) $parts[] = $u . ' updated';
        if ($a) $parts[] = $a . ' added';
        if ($r) $parts[] = $r . ' removed';

        return ['badge' => implode(', ', $parts), 'colour' => '#10b981'];
    };
@endphp

<div style="max-width:640px; margin:32px auto; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.1);">

    {{-- Header --}}
    <div style="background:#0f172a; padding:24px 32px;">
        <div style="font-size:18px; font-weight:700; color:#ffffff; letter-spacing:-0.02em;">{{ $host }}</div>
        <div style="font-size:13px; color:#cbd5e1; margin-top:4px;">Statamic Package Update Report &nbsp;·&nbsp; {{ $sentDate }}</div>
    </div>

    <div style="padding:28px 32px;">

        {{-- Status banner --}}
        <div style="background:{{ $statusAccent }}1a; border-left:3px solid {{ $statusAccent }}; padding:14px 16px; border-radius:6px; margin-bottom:24px;">
            <div style="font-size:15px; font-weight:600; color:#0f172a; line-height:1.4;">{{ $intro }}</div>
            <div style="font-size:13px; color:#475569; margin-top:6px;">{{ $statusLine }}</div>
        </div>

        {{-- Platform rows: Statamic / Laravel / PHP --}}
        @foreach ([
            ['key' => 'statamic', 'label' => 'Statamic'],
            ['key' => 'laravel',  'label' => 'Laravel'],
            ['key' => 'php',      'label' => 'PHP'],
        ] as $row)
            @php $r = $platformRow($row['label'], $platform[$row['key']]); @endphp
            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; margin-bottom:10px;">
                <tr>
                    <td style="padding:12px 16px; font-size:13px; font-weight:600; color:#0f172a; vertical-align:middle;">
                        {{ $r['label'] }}
                    </td>
                    <td style="padding:12px 16px; font-size:12px; color:#475569; font-variant-numeric:tabular-nums; vertical-align:middle; text-align:right; white-space:nowrap;">
                        @if ($r['changed'])
                            {{ $platform[$row['key']]['from'] }} <span style="color:#94a3b8;">→</span> <strong style="color:#0f172a;">{{ $platform[$row['key']]['to'] }}</strong>
                        @else
                            {{ $r['detail'] }}
                        @endif
                        <span style="display:inline-block; margin-left:10px; font-size:11px; font-weight:600; padding:2px 8px; border-radius:4px; color:{{ $r['colour'] }}; border:1px solid {{ $r['colour'] }}; background:#fff;">{{ $r['badge'] }}</span>
                    </td>
                </tr>
            </table>
        @endforeach

        {{-- Composer section --}}
        @php $cs = $ecosystemSummary($composer); @endphp
        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; margin-bottom:10px;">
            <tr style="{{ $composerChanges > 0 ? 'border-bottom:1px solid #f1f5f9;' : '' }}">
                <td style="padding:12px 16px; font-size:13px; font-weight:600; color:#0f172a;">Composer</td>
                <td align="right" style="padding:12px 16px; white-space:nowrap;">
                    <span style="font-size:11px; font-weight:600; padding:2px 8px; border-radius:4px; color:{{ $cs['colour'] }}; border:1px solid {{ $cs['colour'] }}; background:#fff;">{{ $cs['badge'] }}</span>
                </td>
            </tr>
            @foreach ($composer['updated'] as $pkg)
                <tr style="border-top:1px solid #f1f5f9;">
                    <td style="padding:8px 16px; font-size:13px; color:#0f172a;">{{ $pkg['name'] }}</td>
                    <td align="right" style="padding:8px 16px; font-size:12px; color:#475569; font-variant-numeric:tabular-nums; white-space:nowrap;">
                        {{ $pkg['from'] }} <span style="color:#94a3b8;">→</span> <strong style="color:#0f172a;">{{ $pkg['to'] }}</strong>
                    </td>
                </tr>
            @endforeach
            @foreach ($composer['added'] as $pkg)
                <tr style="border-top:1px solid #f1f5f9;">
                    <td style="padding:8px 16px; font-size:13px; color:#0f172a;">{{ $pkg['name'] }}</td>
                    <td align="right" style="padding:8px 16px; font-size:12px; color:#10b981; font-variant-numeric:tabular-nums; white-space:nowrap;">
                        added at {{ $pkg['to'] }}
                    </td>
                </tr>
            @endforeach
            @foreach ($composer['removed'] as $pkg)
                <tr style="border-top:1px solid #f1f5f9;">
                    <td style="padding:8px 16px; font-size:13px; color:#0f172a;">{{ $pkg['name'] }}</td>
                    <td align="right" style="padding:8px 16px; font-size:12px; color:#64748b; font-variant-numeric:tabular-nums; white-space:nowrap;">
                        removed (was {{ $pkg['from'] }})
                    </td>
                </tr>
            @endforeach
        </table>

        {{-- npm section --}}
        @php $ns = $ecosystemSummary($npm); @endphp
        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; margin-bottom:24px;">
            <tr style="{{ $npmChanges > 0 ? 'border-bottom:1px solid #f1f5f9;' : '' }}">
                <td style="padding:12px 16px; font-size:13px; font-weight:600; color:#0f172a;">npm</td>
                <td align="right" style="padding:12px 16px; white-space:nowrap;">
                    <span style="font-size:11px; font-weight:600; padding:2px 8px; border-radius:4px; color:{{ $ns['colour'] }}; border:1px solid {{ $ns['colour'] }}; background:#fff;">{{ $ns['badge'] }}</span>
                </td>
            </tr>
            @foreach ($npm['updated'] as $pkg)
                <tr style="border-top:1px solid #f1f5f9;">
                    <td style="padding:8px 16px; font-size:13px; color:#0f172a;">{{ $pkg['name'] }}</td>
                    <td align="right" style="padding:8px 16px; font-size:12px; color:#475569; font-variant-numeric:tabular-nums; white-space:nowrap;">
                        {{ $pkg['from'] }} <span style="color:#94a3b8;">→</span> <strong style="color:#0f172a;">{{ $pkg['to'] }}</strong>
                    </td>
                </tr>
            @endforeach
            @foreach ($npm['added'] as $pkg)
                <tr style="border-top:1px solid #f1f5f9;">
                    <td style="padding:8px 16px; font-size:13px; color:#0f172a;">{{ $pkg['name'] }}</td>
                    <td align="right" style="padding:8px 16px; font-size:12px; color:#10b981; font-variant-numeric:tabular-nums; white-space:nowrap;">
                        added at {{ $pkg['to'] }}
                    </td>
                </tr>
            @endforeach
            @foreach ($npm['removed'] as $pkg)
                <tr style="border-top:1px solid #f1f5f9;">
                    <td style="padding:8px 16px; font-size:13px; color:#0f172a;">{{ $pkg['name'] }}</td>
                    <td align="right" style="padding:8px 16px; font-size:12px; color:#64748b; font-variant-numeric:tabular-nums; white-space:nowrap;">
                        removed (was {{ $pkg['from'] }})
                    </td>
                </tr>
            @endforeach
        </table>

        {{-- Vulnerabilities (only when relevant) --}}
        @if ($vulnsResolved > 0 || $vulnsIntro > 0)
            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; margin-bottom:24px;">
                <tr style="{{ $vulnsResolved > 0 && $vulnsIntro > 0 ? 'border-bottom:1px solid #f1f5f9;' : '' }}">
                    <td style="padding:12px 16px; font-size:13px; font-weight:600; color:#0f172a;">Vulnerabilities</td>
                    <td align="right" style="padding:12px 16px; font-size:12px; color:#475569;">
                        @if ($vulnsResolved > 0)
                            <span style="color:#10b981; font-weight:600;">{{ $vulnsResolved }} resolved</span>
                        @endif
                        @if ($vulnsResolved > 0 && $vulnsIntro > 0)<span style="color:#94a3b8;"> · </span>@endif
                        @if ($vulnsIntro > 0)
                            <span style="color:#ef4444; font-weight:600;">{{ $vulnsIntro }} new</span>
                        @endif
                    </td>
                </tr>
            </table>
        @endif

        {{-- CTA --}}
        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
            <tr>
                <td align="center" style="padding:4px 0 8px;">
                    <a href="{{ $utility_url }}"
                       style="display:inline-block; background:#0f172a; color:#ffffff; font-size:14px; font-weight:600; text-decoration:none; padding:12px 28px; border-radius:8px;">
                        View current status →
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
