{{--
    D3 Creative - Sentinel Widget
    Statamic version, Laravel version, PHP version, and vulnerability counts by severity.
--}}

@php
    $_devName = $sentinelDevName ?? null;
    $_devUrl  = $sentinelDevUrl ?? null;
    $sentinelBrandSuffix = $_devName
        ? ' by ' . ($_devUrl
            ? '<a href="' . e($_devUrl) . '" target="_blank" style="color:#64748b; text-decoration:underline;">' . e($_devName) . '</a>'
            : e($_devName))
        : '';
@endphp

<div style="background:#fff; border:1px solid #e4e4e7; border-radius:8px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #1e293b;">

@if (! $audit)

    {{-- Empty state: no scan has run yet --}}
    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:8px 18px; border-bottom:1px solid #e4e4e7;">
        <span style="display:inline-flex; align-items:center; gap:8px; font-size:16px; font-weight:500; color:#1b1718;">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" width="18" height="18" style="flex-shrink:0;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
            </svg>
            Sentinel
        </span>
    </div>
    <div style="padding:24px 18px; text-align:center;">
        <p style="font-size:13px; font-weight:600; color:#0f172a; margin:8px 0 4px 0;">No scan yet</p>
        <p style="font-size:12px; color:#64748b; margin:0 0 14px 0; line-height:1.5;">Run your first scan to see Statamic, Laravel, PHP and dependency status.</p>
        <a x-data
           x-init="if (! document.getElementById('sentinel-keyframes')) { var s = document.createElement('style'); s.id = 'sentinel-keyframes'; s.textContent = '@keyframes sentinel-spin { to { transform: rotate(360deg); } }'; document.head.appendChild(s); }"
           x-on:click.prevent="$el.querySelector('[data-sentinel-label]').textContent = 'Scanning…'; $el.querySelector('[data-sentinel-icon]').style.animation = 'sentinel-spin 1s linear infinite'; requestAnimationFrame(() => requestAnimationFrame(() => location.href = $el.href))"
           href="?d3_refresh=1"
           style="display:inline-flex; align-items:center; justify-content:center; gap:8px; white-space:nowrap; font-weight:600; cursor:pointer; text-decoration:none; color:#fff; background:#0f172a; padding:0 16px; height:34px; font-size:13px; line-height:1.25; border-radius:8px;">
            <span data-sentinel-label>Scan Now</span>
            <span data-sentinel-icon aria-hidden="true" style="display:inline-block; font-size:14px; line-height:1; flex-shrink:0; transform-origin:center;">↻</span>
        </a>
        <p style="font-size:11px; color:#64748b; margin:10px 0 0 0;">Takes 10-20 seconds.</p>
    </div>
    <div style="padding:10px 18px; border-top:1px solid #e4e4e7; background:#fafafa; border-radius:0 0 8px 8px;">
        <p style="font-size:11px; color:#64748b; margin:0; letter-spacing:-0.01em;">
            Sentinel{!! $sentinelBrandSuffix !!}. Platform and dependency audits for Statamic sites.
        </p>
    </div>

@else

@php
    extract($audit, EXTR_SKIP);

    // Older cached snapshots (pre-license) lack this key, so extract() won't
    // define $license - fall back explicitly so the row below never errors.
    $license = $audit['license'] ?? ['supported' => false];

    $statusColours = [
        'ok'         => '#047857',
        'active'     => '#047857',
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
        'LOW'      => '#1d4ed8',
        'UNKNOWN'  => '#94a3b8',
    ];

    $statusColour   = fn($s) => $statusColours[$s]   ?? '#94a3b8';
    $severityColour = fn($s) => $severityColours[$s] ?? '#94a3b8';

    $overallOk =
        ($statamic['status'] === 'ok') &&
        in_array($laravel['status'], ['ok', 'active', 'security']) &&
        in_array($php['status'], ['ok', 'active', 'security']) &&
        ($composer['status'] === 'ok' || $composer['status'] === 'unavailable') &&
        ($npm['status'] === 'ok' || $npm['status'] === 'unavailable');

    $hasCritical =
        ($composer['counts']['CRITICAL'] ?? 0) > 0 ||
        ($npm['counts']['CRITICAL'] ?? 0) > 0;
@endphp

    {{-- Card header: title + action --}}
    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:8px 18px; border-bottom:1px solid #e4e4e7;">
        <span style="display:inline-flex; align-items:center; gap:8px; font-size:16px; font-weight:500; color:#1b1718;">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" width="18" height="18" style="flex-shrink:0;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
            </svg>
            Sentinel
        </span>
        @if (auth()->user()?->can('access sentinel utility'))
            <a href="{{ cp_route('utilities.sentinel') }}" style="display:inline-flex; align-items:center; justify-content:center; white-space:nowrap; flex-shrink:0; font-weight:500; cursor:pointer; text-decoration:none; color:#111827; background:linear-gradient(to bottom, #ffffff, #f9fafb); border:1px solid #d1d5db; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); padding:0 12px; height:32px; font-size:13px; line-height:1.25; border-radius:8px;">View Report</a>
        @endif
    </div>

    {{-- Card body --}}
    <div style="padding:16px 18px; font-size:13px;">

    {{-- Version list: Statamic / Laravel / PHP --}}
    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; margin-bottom:8px;">
        @foreach ([
            ['label' => 'Statamic', 'version' => $statamic['current'], 'latest' => $statamic['latest'] ?? null, 'status' => $statamic['status'], 'security' => $statamic['security_update_available'] ?? false],
            ['label' => 'Laravel',  'version' => $laravel['version'],  'latest' => $laravel['latest']  ?? null, 'status' => $laravel['status'],  'security' => $laravel['security_update_available']  ?? false],
            ['label' => 'PHP',      'version' => $php['version'],      'latest' => $php['latest']      ?? null, 'status' => $php['status'],      'security' => false],
        ] as $card)
            @php
                $outdated   = ! empty($card['latest']) && version_compare($card['version'], $card['latest'], '<');
                $isEol      = $card['status'] === 'eol';
                $pillColour = ($card['security'] || $isEol) ? '#dc2626' : ($outdated ? '#1d4ed8' : '#047857');
            @endphp
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:6px 12px; {{ ! $loop->last ? 'border-bottom:1px solid #e2e8f0;' : '' }}">
                <span style="font-size:11px; font-weight:600; color:#0f172a;">{{ $card['label'] }}</span>
                <span style="display:inline-flex; align-items:center; font-size:10px; font-weight:500; padding:1px 7px; border-radius:4px; color:{{ $pillColour }}; background:#fff; border:1px solid {{ $pillColour }}; flex-shrink:0; font-variant-numeric:tabular-nums;">
                    @if($outdated)
                        {{ $card['latest'] }}
                    @elseif($isEol)
                        {{ $card['version'] }} (EOL)
                    @else
                        {{ $card['version'] }}
                    @endif
                </span>
            </div>
        @endforeach
        @if(! empty($license['supported']))
            @php
                // Statamic exposes a "needs renewal" state (license doesn't cover
                // the running version), not a renewal date. Map it to a pill.
                $licenseText   = ['ok' => 'Licensed', 'renewal' => 'Renewal due', 'invalid' => 'Not licensed', 'trial' => 'Trial', 'free' => 'Free edition', 'unknown' => 'Unverified'][$license['status'] ?? 'unknown'] ?? 'Unverified';
                $licenseColour = in_array($license['status'] ?? '', ['renewal', 'invalid']) ? '#dc2626' : (($license['status'] ?? '') === 'ok' ? '#047857' : '#64748b');
            @endphp
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:6px 12px; border-top:1px solid #e2e8f0;">
                <span style="font-size:11px; font-weight:600; color:#0f172a;">License</span>
                <span style="display:inline-flex; align-items:center; font-size:10px; font-weight:500; padding:1px 7px; border-radius:4px; color:{{ $licenseColour }}; background:#fff; border:1px solid {{ $licenseColour }}; flex-shrink:0;">{{ $licenseText }}</span>
            </div>
        @endif
    </div>

    {{-- Package audit rows --}}
    @foreach ([
        ['label' => 'Composer packages', 'data' => $composer, 'tooltip' => "PHP packages that power your site's backend - including Statamic itself, Laravel (the framework it runs on), and any installed add-ons. Keeping these up to date is important for security and stability."],
        ['label' => 'npm packages',      'data' => $npm,      'tooltip' => "JavaScript packages used to build your site's frontend - things like scripts and styles that run in your visitors' browsers. Updates often include security fixes and improvements."],
    ] as $row)
    @php
        $d            = $row['data'];
        $totalVulns   = array_sum($d['counts'] ?? []);
        // Vendor-flagged security updates (no matching OSV advisory yet) - count
        // them toward the security-issues badge so the widget matches the
        // utility view and Statamic's own updater notification.
        $vendorOnly   = (int) ($d['outdated']['vendor_security_updates_total'] ?? 0);
        $totalSec     = $totalVulns + $vendorOnly;
        $updatesTotal = $d['outdated']['total'] ?? 0;
        $hasData      = in_array($d['status'], ['ok', 'vulnerable']);
    @endphp
    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; margin-bottom:8px;">

        {{-- Title --}}
        <div style="display:flex; align-items:center; gap:5px; padding:8px 12px; {{ $hasData ? 'border-bottom:1px solid #e2e8f0;' : '' }}">
            <span style="font-weight:600; font-size:12px;">
                @if($d['status'] === 'unavailable')
                    {{ $row['label'] }} - lock file not found
                @elseif($d['status'] === 'error')
                    {{ $row['label'] }} - <span style="color:#ef4444;">⚠ check failed</span>
                @else
                    {{ $d['total_packages'] }} {{ $row['label'] }} scanned
                @endif
            </span>
            <span x-data="{ show: false }" x-on:keydown.escape.window="show = false" x-on:sentinel-tooltip-open.window="if ($event.detail !== $root) show = false" style="position:relative; display:inline-flex; align-items:center;">
                <button type="button" x-on:click.stop="show = !show; if (show) $dispatch('sentinel-tooltip-open', $root)" aria-label="About {{ $row['label'] }}" style="display:inline-flex; align-items:center; justify-content:center; background:transparent; border:0; padding:0; color:#64748b; cursor:pointer; outline:none;">
                    <span style="display:inline-flex; align-items:center; justify-content:center; width:14px; height:14px; border:1.2px solid currentColor; border-radius:50%; font-size:10px; font-weight:600; line-height:1; box-sizing:border-box; font-family:inherit;">?</span>
                </button>
                <span x-show="show" x-cloak x-on:click.outside="show = false" style="position:absolute; bottom:calc(100% + 8px); left:50%; transform:translateX(-50%); width:260px; background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px; font-size:12px; font-weight:400; color:#1e293b; line-height:1.55; box-shadow:0 8px 24px rgba(15,23,42,0.1); z-index:30; letter-spacing:-0.01em;">
                    {{ $row['tooltip'] }}
                    <span style="position:absolute; top:100%; left:50%; transform:translateX(-50%) rotate(45deg); width:10px; height:10px; background:#fff; border-right:1px solid #e2e8f0; border-bottom:1px solid #e2e8f0; margin-top:-5px;"></span>
                </span>
            </span>
        </div>

        @if($hasData)
            {{-- Security issues row --}}
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:6px 12px; border-bottom:1px solid #e2e8f0;">
                <span style="font-size:11px; font-weight:600; color:#0f172a;">Security issues</span>
                @if($totalSec > 0)
                    <span style="display:inline-flex; align-items:center; font-size:10px; font-weight:500; padding:1px 7px; border-radius:4px; color:#dc2626; background:#fff; border:1px solid #dc2626; flex-shrink:0; font-variant-numeric:tabular-nums;"
                          @if($vendorOnly > 0 && $totalVulns === 0) title="Vendor-flagged security release available" @endif>{{ $totalSec }}</span>
                @else
                    <span style="display:inline-flex; align-items:center; font-size:10px; font-weight:500; padding:1px 7px; border-radius:4px; color:#047857; background:#fff; border:1px solid #047857; flex-shrink:0; font-variant-numeric:tabular-nums;">0</span>
                @endif
            </div>

            {{-- Updates row --}}
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:6px 12px;">
                <span style="font-size:11px; font-weight:600; color:#0f172a;">Updates available</span>
                @if($updatesTotal > 0)
                    <span style="display:inline-flex; align-items:center; font-size:10px; font-weight:500; padding:1px 7px; border-radius:4px; color:#1d4ed8; background:#fff; border:1px solid #1d4ed8; flex-shrink:0; font-variant-numeric:tabular-nums;">{{ $updatesTotal }}</span>
                @else
                    <span style="display:inline-flex; align-items:center; font-size:10px; font-weight:500; padding:1px 7px; border-radius:4px; color:#047857; background:#fff; border:1px solid #047857; flex-shrink:0; font-variant-numeric:tabular-nums;">0</span>
                @endif
            </div>
        @endif

    </div>
    @endforeach

        {{-- Meta: last scanned + refresh --}}
        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-top:4px; font-size:12px; color:#64748b;">
            <span>Last scanned: {{ $audited_at }}</span>
            <a x-data
               x-init="if (! document.getElementById('sentinel-keyframes')) { var s = document.createElement('style'); s.id = 'sentinel-keyframes'; s.textContent = '@keyframes sentinel-spin { to { transform: rotate(360deg); } }'; document.head.appendChild(s); }"
               x-on:click.prevent="$el.querySelector('[data-sentinel-label]').textContent = 'Scanning…'; $el.querySelector('[data-sentinel-icon]').style.animation = 'sentinel-spin 1s linear infinite'; requestAnimationFrame(() => requestAnimationFrame(() => location.href = $el.href))"
               href="?d3_refresh=1"
               title="Refresh audit results"
               style="display:inline-flex; align-items:center; gap:4px; color:#64748b; text-decoration:none;">
                <span data-sentinel-icon aria-hidden="true" style="display:inline-block; font-size:14px; line-height:1; flex-shrink:0; transform-origin:center;">↻</span>
                <span data-sentinel-label>Refresh</span>
            </a>
        </div>

    </div>{{-- /card body --}}

    {{-- Card footer --}}
    <div style="padding:10px 18px; border-top:1px solid #e4e4e7; background:#fafafa; border-radius:0 0 8px 8px;">
        <p style="font-size:11px; color:#64748b; margin:0; letter-spacing:-0.01em;">
            Sentinel{!! $sentinelBrandSuffix !!}. Platform and dependency audits for Statamic sites.
        </p>
    </div>

@endif

</div>{{-- /card --}}
