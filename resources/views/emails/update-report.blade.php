<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no,address=no,email=no,date=no,url=no">
    <title>Statamic Package Update Report</title>
    <style>
        /* On narrow screens the two-column row layout squeezes the version +
           pill into a cramped column, so stack each row: label, description,
           then version + pill drop onto their own full-width rows. */
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

    $hasAnyChange = $platformChangeCount > 0 || $composerChanges > 0 || $npmChanges > 0 || $vulnsResolved > 0 || $vulnsIntro > 0;
    $statusAccent = $vulnsIntro > 0 ? '#ef4444' : ($hasAnyChange ? '#10b981' : '#94a3b8');

    if ($vulnsIntro > 0) {
        $intro = 'Your Statamic website has been updated, but new security issues need attention.';
    } elseif ($hasAnyChange) {
        $intro = 'Your Statamic website has been updated.';
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

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f1f5f9;">
<tr>
<td align="center" style="padding:32px 16px;">
<table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="max-width:640px; width:100%; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.1);">
<tr><td>

    {{-- Header --}}
    <div style="background:#0f172a; padding:24px 32px;">
        <div style="font-size:18px; font-weight:700; letter-spacing:-0.02em;">@foreach ($hosts as $i => $h)@if ($i)<span style="color:#cbd5e1;">, </span>@endif<a href="https://{{ $h }}" style="color:#ffffff; text-decoration:none;">{{ $h }}</a>@endforeach</div>
        <div style="font-size:13px; color:#cbd5e1; margin-top:4px;">Statamic Package Update Report &nbsp;·&nbsp; {{ $sentDate }}</div>
    </div>

    <div style="padding:28px 32px;">

        {{-- Status banner --}}
        <div style="background:{{ $statusAccent }}1a; border-left:3px solid {{ $statusAccent }}; padding:14px 16px; border-radius:6px; margin-bottom:24px;">
            <div style="font-size:15px; font-weight:600; color:#0f172a; line-height:1.4;">{{ $intro }}</div>
        </div>

        {{-- Platform rows: Statamic / Laravel / PHP --}}
        @foreach ([
            ['key' => 'statamic', 'label' => 'Statamic', 'description' => 'The CMS that powers your website'],
            ['key' => 'laravel',  'label' => 'Laravel',  'description' => 'The framework Statamic is built on'],
            ['key' => 'php',      'label' => 'PHP',      'description' => 'The server-side language that runs everything'],
        ] as $row)
            @php $r = $platformRow($row['label'], $platform[$row['key']]); @endphp
            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; margin-bottom:10px;">
                <tr>
                    <td class="sentinel-row-cell" style="padding:12px 16px; vertical-align:middle;">
                        <div style="font-size:13px; font-weight:600; color:#0f172a;">{{ $r['label'] }}</div>
                        <div style="font-size:12px; color:#475569; margin-top:3px;">{{ $row['description'] }}</div>
                    </td>
                    <td class="sentinel-row-cell sentinel-row-meta" style="padding:12px 16px; font-size:12px; color:#475569; font-variant-numeric:tabular-nums; vertical-align:middle; text-align:right; white-space:nowrap;">
                        @if ($r['changed'])
                            {{ $platform[$row['key']]['from'] }} <span style="color:#94a3b8;">→</span> <strong style="color:#0f172a;">{{ $platform[$row['key']]['to'] }}</strong>
                        @else
                            {{ $r['detail'] }}
                        @endif
                        <span class="sentinel-pill" style="display:inline-block; margin-left:10px; font-size:11px; font-weight:600; padding:2px 8px; border-radius:4px; color:{{ $r['colour'] }}; border:1px solid {{ $r['colour'] }}; background:#fff;">{{ $r['badge'] }}</span>
                    </td>
                </tr>
            </table>
        @endforeach

        {{-- Composer section --}}
        @php $cs = $ecosystemSummary($composer); @endphp
        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; margin-bottom:10px;">
            <tr style="{{ $composerChanges > 0 ? 'border-bottom:1px solid #f1f5f9;' : '' }}">
                <td class="sentinel-row-cell" style="padding:12px 16px; vertical-align:middle;">
                    <div style="font-size:13px; font-weight:600; color:#0f172a;">Composer</div>
                    <div style="font-size:12px; color:#475569; margin-top:3px;">Third-party PHP packages your site uses</div>
                </td>
                <td align="right" class="sentinel-row-cell sentinel-row-meta" style="padding:12px 16px; white-space:nowrap; vertical-align:middle;">
                    <span style="font-size:11px; font-weight:600; padding:2px 8px; border-radius:4px; color:{{ $cs['colour'] }}; border:1px solid {{ $cs['colour'] }}; background:#fff;">{{ $cs['badge'] }}</span>
                </td>
            </tr>
            @foreach ($composer['updated'] as $pkg)
                <tr style="border-top:1px solid #f1f5f9;">
                    <td class="sentinel-row-cell" style="padding:8px 16px; font-size:13px; color:#0f172a;">{{ $pkg['name'] }}</td>
                    <td align="right" class="sentinel-row-cell sentinel-row-meta" style="padding:8px 16px; font-size:12px; color:#475569; font-variant-numeric:tabular-nums; white-space:nowrap;">
                        {{ $pkg['from'] }} <span style="color:#94a3b8;">→</span> <strong style="color:#0f172a;">{{ $pkg['to'] }}</strong>
                    </td>
                </tr>
            @endforeach
            @foreach ($composer['added'] as $pkg)
                <tr style="border-top:1px solid #f1f5f9;">
                    <td class="sentinel-row-cell" style="padding:8px 16px; font-size:13px; color:#0f172a;">{{ $pkg['name'] }}</td>
                    <td align="right" class="sentinel-row-cell sentinel-row-meta" style="padding:8px 16px; font-size:12px; color:#10b981; font-variant-numeric:tabular-nums; white-space:nowrap;">
                        added at {{ $pkg['to'] }}
                    </td>
                </tr>
            @endforeach
            @foreach ($composer['removed'] as $pkg)
                <tr style="border-top:1px solid #f1f5f9;">
                    <td class="sentinel-row-cell" style="padding:8px 16px; font-size:13px; color:#0f172a;">{{ $pkg['name'] }}</td>
                    <td align="right" class="sentinel-row-cell sentinel-row-meta" style="padding:8px 16px; font-size:12px; color:#64748b; font-variant-numeric:tabular-nums; white-space:nowrap;">
                        removed (was {{ $pkg['from'] }})
                    </td>
                </tr>
            @endforeach
        </table>

        {{-- npm section --}}
        @php $ns = $ecosystemSummary($npm); @endphp
        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; margin-bottom:24px;">
            <tr style="{{ $npmChanges > 0 ? 'border-bottom:1px solid #f1f5f9;' : '' }}">
                <td class="sentinel-row-cell" style="padding:12px 16px; vertical-align:middle;">
                    <div style="font-size:13px; font-weight:600; color:#0f172a;">npm</div>
                    <div style="font-size:12px; color:#475569; margin-top:3px;">Third-party JavaScript packages your site uses</div>
                </td>
                <td align="right" class="sentinel-row-cell sentinel-row-meta" style="padding:12px 16px; white-space:nowrap; vertical-align:middle;">
                    <span style="font-size:11px; font-weight:600; padding:2px 8px; border-radius:4px; color:{{ $ns['colour'] }}; border:1px solid {{ $ns['colour'] }}; background:#fff;">{{ $ns['badge'] }}</span>
                </td>
            </tr>
            @foreach ($npm['updated'] as $pkg)
                <tr style="border-top:1px solid #f1f5f9;">
                    <td class="sentinel-row-cell" style="padding:8px 16px; font-size:13px; color:#0f172a;">{{ $pkg['name'] }}</td>
                    <td align="right" class="sentinel-row-cell sentinel-row-meta" style="padding:8px 16px; font-size:12px; color:#475569; font-variant-numeric:tabular-nums; white-space:nowrap;">
                        {{ $pkg['from'] }} <span style="color:#94a3b8;">→</span> <strong style="color:#0f172a;">{{ $pkg['to'] }}</strong>
                    </td>
                </tr>
            @endforeach
            @foreach ($npm['added'] as $pkg)
                <tr style="border-top:1px solid #f1f5f9;">
                    <td class="sentinel-row-cell" style="padding:8px 16px; font-size:13px; color:#0f172a;">{{ $pkg['name'] }}</td>
                    <td align="right" class="sentinel-row-cell sentinel-row-meta" style="padding:8px 16px; font-size:12px; color:#10b981; font-variant-numeric:tabular-nums; white-space:nowrap;">
                        added at {{ $pkg['to'] }}
                    </td>
                </tr>
            @endforeach
            @foreach ($npm['removed'] as $pkg)
                <tr style="border-top:1px solid #f1f5f9;">
                    <td class="sentinel-row-cell" style="padding:8px 16px; font-size:13px; color:#0f172a;">{{ $pkg['name'] }}</td>
                    <td align="right" class="sentinel-row-cell sentinel-row-meta" style="padding:8px 16px; font-size:12px; color:#64748b; font-variant-numeric:tabular-nums; white-space:nowrap;">
                        removed (was {{ $pkg['from'] }})
                    </td>
                </tr>
            @endforeach
        </table>

        {{-- Vulnerabilities (only when relevant) --}}
        @if ($vulnsResolved > 0 || $vulnsIntro > 0)
            @php
                $resolvedPkgs = array_merge(
                    $vulns['composer_resolved_packages']   ?? [],
                    $vulns['npm_resolved_packages']        ?? []
                );
                $introPkgs = array_merge(
                    $vulns['composer_introduced_packages'] ?? [],
                    $vulns['npm_introduced_packages']      ?? []
                );

                $formatVulnPkgs = function (array $pkgs) {
                    return implode(', ', array_map(
                        fn ($p) => $p['count'] > 1
                            ? $p['name'] . ' (' . $p['count'] . ')'
                            : $p['name'],
                        $pkgs
                    ));
                };
            @endphp
            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; margin-bottom:24px;">
                <tr style="{{ $vulnsResolved > 0 && $vulnsIntro > 0 ? 'border-bottom:1px solid #f1f5f9;' : '' }}">
                    <td class="sentinel-row-cell" style="padding:12px 16px; font-size:13px; font-weight:600; color:#0f172a;">Vulnerabilities</td>
                    <td align="right" class="sentinel-row-cell sentinel-row-meta" style="padding:12px 16px; font-size:12px; color:#475569;">
                        @if ($vulnsResolved > 0)
                            <div>
                                <span style="color:#10b981; font-weight:600;">{{ $vulnsResolved }} resolved</span>
                                @if (! empty($resolvedPkgs))
                                    <div style="font-size:11px; color:#64748b; font-weight:400; margin-top:2px;">{{ $formatVulnPkgs($resolvedPkgs) }}</div>
                                @endif
                            </div>
                        @endif
                        @if ($vulnsIntro > 0)
                            <div style="{{ $vulnsResolved > 0 ? 'margin-top:6px;' : '' }}">
                                <span style="color:#ef4444; font-weight:600;">{{ $vulnsIntro }} new</span>
                                @if (! empty($introPkgs))
                                    <div style="font-size:11px; color:#64748b; font-weight:400; margin-top:2px;">{{ $formatVulnPkgs($introPkgs) }}</div>
                                @endif
                            </div>
                        @endif
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
