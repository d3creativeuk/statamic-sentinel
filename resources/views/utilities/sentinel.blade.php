{{--
    D3 Creative - Sentinel Utility (full report)
    Mirrors the widget data, expanded: full vulnerability list + full outdated list.
--}}

@php
    $_devName = $sentinelDevName ?? null;
    $_devUrl  = $sentinelDevUrl ?? null;
    $sentinelBrandSuffix = $_devName
        ? ' by ' . ($_devUrl
            ? '<a href="' . e($_devUrl) . '" target="_blank" style="color:rgb(63 63 71); text-decoration:underline;">' . e($_devName) . '</a>'
            : e($_devName))
        : '';
@endphp

@extends('statamic::layout')

@section('title', 'Sentinel')

@section('content')

{{-- Statamic 6 extracts this view's content out of the CP layout, which drops a
     static <style> tag, so inject the CVE hover rule into <head> (same pattern as
     the spinner keyframes). --}}
<div x-data x-init="if (! document.getElementById('d3-sentinel-cve-style')) { var s = document.createElement('style'); s.id = 'd3-sentinel-cve-style'; s.textContent = '.d3-sentinel-cve{text-decoration:none} .d3-sentinel-cve:hover{text-decoration:underline}'; document.head.appendChild(s); } $nextTick(() => window.scrollTo({ top: 0, behavior: 'instant' }))" style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-size: 14px; color: #1e293b;">

@if (! $audit)

    {{-- Empty state: no scan has run yet --}}
    <header style="display:flex; align-items:center; gap:10px; padding:32px 0;">
        <h1 style="display:flex; align-items:center; gap:10px; font-size:25px; line-height:1.25; font-weight:500; color:#0f172a; margin:0; -webkit-font-smoothing:antialiased;">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" width="22" height="22" style="color:#0f172a; flex-shrink:0;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
            </svg>
            Sentinel
        </h1>
    </header>
    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:48px 24px; text-align:center;">
        <p style="font-size:18px; font-weight:600; color:#0f172a; margin:0 0 6px 0;">No scan yet</p>
        <p style="font-size:13px; color:#64748b; margin:0 0 20px 0; line-height:1.55; max-width:420px; margin-left:auto; margin-right:auto;">Run your first scan to see Statamic, Laravel, PHP and dependency status. Re-run any time using the Refresh button.</p>
        <a x-data
           x-init="if (! document.getElementById('sentinel-keyframes')) { var s = document.createElement('style'); s.id = 'sentinel-keyframes'; s.textContent = '@keyframes sentinel-spin { to { transform: rotate(360deg); } }'; document.head.appendChild(s); }"
           x-on:click.prevent="$el.querySelector('[data-sentinel-label]').textContent = 'Scanning…'; $el.querySelector('[data-sentinel-icon]').style.animation = 'sentinel-spin 1s linear infinite'; requestAnimationFrame(() => requestAnimationFrame(() => location.href = $el.href + location.hash))"
           href="?d3_refresh=1"
           style="display:inline-flex; align-items:center; justify-content:center; gap:8px; white-space:nowrap; font-weight:600; cursor:pointer; text-decoration:none; color:#fff; background:#0f172a; padding:0 18px; height:38px; font-size:13px; line-height:1.25; border-radius:8px;">
            <span data-sentinel-label>Scan Now</span>
            <span data-sentinel-icon aria-hidden="true" style="display:inline-block; font-size:14px; line-height:1; flex-shrink:0; transform-origin:center;">↻</span>
        </a>
        <p style="font-size:11px; color:#64748b; margin:14px 0 0 0;">Takes 10-20 seconds.</p>
    </div>
    <div style="display:flex; align-items:center; justify-content:flex-start; margin-top:16px; padding-top:14px;">
        <p style="font-size:12px; color:rgb(63 63 71); margin:0; letter-spacing:-0.01em;">
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

    $userEmail = auth()->user()?->email ?? '';

    // Reports, history, scheduling and content-freeze controls all drive
    // super-only POST endpoints (SentinelController / FreezeController abort
    // 403 for non-supers). A non-super granted `access sentinel utility` can
    // open this page, so hide every tab but Current for them - the Current
    // tab carries the same audit data the Status Report would email, and the
    // Refresh link (an unguarded ?d3_refresh GET) stays available to all.
    $isSuper = auth()->user()?->isSuper() === true;

    // Pre-fill each one-off send field with the recipients last entered for
    // that report (extra addresses included), falling back to the current
    // user's own email until they've sent one. $last_*_recipients come from
    // the most recent manual send recorded by SentMailService.
    $statusEmail = ! empty($last_status_recipients ?? []) ? implode(', ', $last_status_recipients) : $userEmail;
    $updateEmail = ! empty($last_update_recipients ?? []) ? implode(', ', $last_update_recipients) : $userEmail;
    $maintenanceEmail = ! empty($last_maintenance_recipients ?? []) ? implode(', ', $last_maintenance_recipients) : $userEmail;

    $plan = $maintenance_plan ?? [];
@endphp

    {{-- Header --}}
    <header style="position:relative; display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:16px; padding:32px 0;">
        <h1 style="display:flex; align-items:center; gap:10px; font-size:25px; line-height:1.25; font-weight:500; color:#0f172a; margin:0; -webkit-font-smoothing:antialiased;">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" width="22" height="22" style="color:#0f172a; flex-shrink:0;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
            </svg>
            Sentinel
        </h1>
        <div style="display:flex; align-items:center; gap:14px;">
            <span style="font-size:12px; color:rgb(63 63 71);">Last scanned: {{ $audited_at }}</span>
            <a x-data
               x-init="if (! document.getElementById('sentinel-keyframes')) { var s = document.createElement('style'); s.id = 'sentinel-keyframes'; s.textContent = '@keyframes sentinel-spin { to { transform: rotate(360deg); } }'; document.head.appendChild(s); }"
               x-on:click.prevent="$el.querySelector('[data-sentinel-label]').textContent = 'Scanning…'; $el.querySelector('[data-sentinel-icon]').style.animation = 'sentinel-spin 1s linear infinite'; requestAnimationFrame(() => requestAnimationFrame(() => location.href = $el.href + location.hash))"
               href="?d3_refresh=1"
               title="Refresh audit results"
               style="display:inline-flex; align-items:center; gap:4px; font-size:12px; color:rgb(63 63 71); text-decoration:none;">
                <span data-sentinel-icon aria-hidden="true" style="display:inline-block; font-size:14px; line-height:1; flex-shrink:0; transform-origin:center;">↻</span>
                <span data-sentinel-label>Refresh</span>
            </a>
        </div>
    </header>

    {{-- Tabs --}}
    {{-- Tab state is mirrored to location.hash (e.g. #history) so refresh and
         back/forward land on the active tab instead of jumping to Current. --}}
    <div x-data="{
        tab: 'current',
        init() {
            var valid = {!! $isSuper ? "['current', 'history', 'status-report', 'update-report', 'maintenance-report', 'content-freeze']" : "['current']" !!};
            var hash = (window.location.hash || '').replace('#', '');
            if (valid.indexOf(hash) !== -1) this.tab = hash;
            this.$watch('tab', function (v) {
                window.history.replaceState(null, '', '#' + v);
            });
            window.addEventListener('hashchange', () => {
                var h = (window.location.hash || '').replace('#', '');
                if (valid.indexOf(h) !== -1 && this.tab !== h) this.tab = h;
            });
        }
    }">

        @if ($isSuper)
        <div role="tablist" style="display:flex; gap:4px; border-bottom:1px solid #e2e8f0; margin-bottom:18px;">
            <button type="button"
                    role="tab"
                    x-on:click="tab = 'current'"
                    x-bind:aria-selected="tab === 'current'"
                    x-bind:style="tab === 'current'
                        ? 'cursor:pointer; background:transparent; border:0; border-bottom:2px solid #0f172a; padding:10px 14px; margin-bottom:-1px; font-size:13px; font-weight:600; font-family:inherit; color:#0f172a;'
                        : 'cursor:pointer; background:transparent; border:0; border-bottom:2px solid transparent; padding:10px 14px; margin-bottom:-1px; font-size:13px; font-weight:600; font-family:inherit; color:#64748b;'">
                Current
            </button>
            <button type="button"
                    role="tab"
                    x-on:click="tab = 'history'"
                    x-bind:aria-selected="tab === 'history'"
                    x-bind:style="tab === 'history'
                        ? 'cursor:pointer; background:transparent; border:0; border-bottom:2px solid #0f172a; padding:10px 14px; margin-bottom:-1px; font-size:13px; font-weight:600; font-family:inherit; color:#0f172a;'
                        : 'cursor:pointer; background:transparent; border:0; border-bottom:2px solid transparent; padding:10px 14px; margin-bottom:-1px; font-size:13px; font-weight:600; font-family:inherit; color:#64748b;'">
                History
                @if (! empty($history))
                    <span style="display:inline-block; margin-left:6px; padding:1px 7px; border-radius:9px; background:#e2e8f0; color:#475569; font-size:11px; font-weight:600;">{{ count($history) }}</span>
                @endif
            </button>
            <button type="button"
                    role="tab"
                    x-on:click="tab = 'status-report'"
                    x-bind:aria-selected="tab === 'status-report'"
                    x-bind:style="tab === 'status-report'
                        ? 'cursor:pointer; background:transparent; border:0; border-bottom:2px solid #0f172a; padding:10px 14px; margin-bottom:-1px; font-size:13px; font-weight:600; font-family:inherit; color:#0f172a;'
                        : 'cursor:pointer; background:transparent; border:0; border-bottom:2px solid transparent; padding:10px 14px; margin-bottom:-1px; font-size:13px; font-weight:600; font-family:inherit; color:#64748b;'">
                Status Report
            </button>
            <button type="button"
                    role="tab"
                    x-on:click="tab = 'update-report'"
                    x-bind:aria-selected="tab === 'update-report'"
                    x-bind:style="tab === 'update-report'
                        ? 'cursor:pointer; background:transparent; border:0; border-bottom:2px solid #0f172a; padding:10px 14px; margin-bottom:-1px; font-size:13px; font-weight:600; font-family:inherit; color:#0f172a;'
                        : 'cursor:pointer; background:transparent; border:0; border-bottom:2px solid transparent; padding:10px 14px; margin-bottom:-1px; font-size:13px; font-weight:600; font-family:inherit; color:#64748b;'">
                Update Report
            </button>
            <button type="button"
                    role="tab"
                    x-on:click="tab = 'maintenance-report'"
                    x-bind:aria-selected="tab === 'maintenance-report'"
                    x-bind:style="tab === 'maintenance-report'
                        ? 'cursor:pointer; background:transparent; border:0; border-bottom:2px solid #0f172a; padding:10px 14px; margin-bottom:-1px; font-size:13px; font-weight:600; font-family:inherit; color:#0f172a;'
                        : 'cursor:pointer; background:transparent; border:0; border-bottom:2px solid transparent; padding:10px 14px; margin-bottom:-1px; font-size:13px; font-weight:600; font-family:inherit; color:#64748b;'">
                Maintenance Report
            </button>
            <button type="button"
                    role="tab"
                    x-on:click="tab = 'content-freeze'"
                    x-bind:aria-selected="tab === 'content-freeze'"
                    x-bind:style="tab === 'content-freeze'
                        ? 'cursor:pointer; background:transparent; border:0; border-bottom:2px solid #0f172a; padding:10px 14px; margin-bottom:-1px; margin-left:auto; font-size:13px; font-weight:600; font-family:inherit; color:#0f172a;'
                        : 'cursor:pointer; background:transparent; border:0; border-bottom:2px solid transparent; padding:10px 14px; margin-bottom:-1px; margin-left:auto; font-size:13px; font-weight:600; font-family:inherit; color:#64748b;'">
                Notify
                @if (! empty($freeze_current) && ($freeze_current['status'] ?? null) === \D3Creative\Sentinel\Services\ContentFreezeService::STATUS_ACTIVE)
                    <span style="display:inline-block; margin-left:6px; padding:1px 7px; border-radius:9px; background:#fef3c7; color:#92400e; font-size:11px; font-weight:600;">Active</span>
                @elseif (! empty($freeze_current))
                    <span style="display:inline-block; margin-left:6px; padding:1px 7px; border-radius:9px; background:#dbeafe; color:#1d4ed8; font-size:11px; font-weight:600;">Scheduled</span>
                @endif
            </button>
        </div>
        @endif

        {{-- Current tab --}}
        <div x-show="tab === 'current'" role="tabpanel">

    {{-- Version list: Statamic / Laravel / PHP --}}
    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; margin-bottom:12px;">
        @foreach ([
            ['label' => 'Statamic', 'version' => $statamic['current'], 'latest' => $statamic['latest'] ?? null, 'status' => $statamic['status'], 'security' => $statamic['security_update_available'] ?? false, 'behind' => $statamic['releases_behind'] ?? null],
            ['label' => 'Laravel',  'version' => $laravel['version'],  'latest' => $laravel['latest']  ?? null, 'status' => $laravel['status'],  'security' => $laravel['security_update_available']  ?? false, 'behind' => $laravel['releases_behind'] ?? null],
            ['label' => 'PHP',      'version' => $php['version'],      'latest' => $php['latest']      ?? null, 'status' => $php['status'],      'security' => false, 'behind' => $php['releases_behind'] ?? null],
        ] as $card)
            @php
                $outdated = ! empty($card['latest']) && version_compare($card['version'], $card['latest'], '<');
                $isEol    = $card['status'] === 'eol';
                // Only alert states (security / EOL) keep the red pill; a plain
                // outdated or up-to-date version renders as bare coloured text.
                $isAlert    = $card['security'] || $isEol;
                $pillColour = $isAlert ? '#dc2626' : ($outdated ? '#0f172a' : '#047857');
                $pillChrome = $isAlert ? 'padding:1px 7px; border-radius:4px; background:#fff; border:1px solid ' . $pillColour . ';' : '';
                $behind     = (int) ($card['behind'] ?? 0);
            @endphp
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:8px 14px; {{ ! $loop->last ? 'border-bottom:1px solid #e2e8f0;' : '' }}">
                <span style="display:inline-flex; align-items:baseline; gap:8px; min-width:0;">
                    <span style="font-size:13px; font-weight:600; color:#0f172a;">{{ $card['label'] }}</span>
                    @if($behind > 0)
                        <span style="font-size:11px; font-weight:500; color:#64748b;">{{ $behind }} behind</span>
                    @endif
                </span>
                <span style="display:inline-flex; align-items:center; font-size:11px; font-weight:500; color:{{ $pillColour }}; flex-shrink:0; font-variant-numeric:tabular-nums; {{ $pillChrome }}">
                    @if($card['security'] && $outdated)
                        Security: {{ $card['version'] }} → {{ $card['latest'] }}
                    @elseif($outdated)
                        {{ $card['version'] }} → {{ $card['latest'] }}
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
                // Statamic reports "needs renewal" (license doesn't cover the
                // running version) rather than a renewal date; the real date
                // lives in the statamic.com account the link points to. The
                // covered version range is deliberately not shown - it's raw
                // constraint syntax that confuses non-technical users.
                $licenseText   = ['ok' => 'Licensed', 'renewal' => 'Renewal due', 'invalid' => 'Not licensed', 'trial' => 'Trial', 'free' => 'Free edition', 'unknown' => 'Unverified'][$license['status'] ?? 'unknown'] ?? 'Unverified';
                $licenseAlert  = in_array($license['status'] ?? '', ['renewal', 'invalid']);
                $licenseColour = $licenseAlert ? '#dc2626' : (($license['status'] ?? '') === 'ok' ? '#047857' : '#64748b');
                // Always render as a bordered pill (matching the widget), whatever the state.
                $licenseChrome = 'padding:1px 7px; border-radius:4px; background:#fff; border:1px solid ' . $licenseColour . ';';
            @endphp
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:8px 14px; border-top:1px solid #e2e8f0;">
                <span style="display:inline-flex; align-items:baseline; gap:8px; min-width:0; flex-wrap:wrap;">
                    <span style="font-size:13px; font-weight:600; color:#0f172a;">Statamic License Status</span>
                    @if(! empty($license['account_url']) && $licenseAlert)
                        <a href="{{ $license['account_url'] }}" target="_blank" rel="noopener" style="font-size:11px; font-weight:500; color:#1d4ed8; text-decoration:none;">View renewal date &#8599;</a>
                    @endif
                </span>
                <span style="display:inline-flex; align-items:center; font-size:11px; font-weight:500; color:{{ $licenseColour }}; flex-shrink:0; {{ $licenseChrome }}">{{ $licenseText }}</span>
            </div>
        @endif
    </div>

    {{-- Package audit sections --}}
    @foreach ([
        ['label' => 'Composer packages', 'data' => $composer, 'tooltip' => "PHP packages that power your site's backend - including Statamic itself, Laravel (the framework it runs on), and any installed add-ons. Keeping these up to date is important for security and stability."],
        ['label' => 'npm packages',      'data' => $npm,      'tooltip' => "JavaScript packages used to build your site's frontend - things like scripts and styles that run in your visitors' browsers. Updates often include security fixes and improvements."],
    ] as $row)
    @php $d = $row['data']; @endphp
    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px; margin-bottom:12px;">

        <div style="display:flex; align-items:center; gap:6px; margin-bottom:12px;">
            <span style="font-weight:600; font-size:14px;">
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
                    <span style="display:inline-flex; align-items:center; justify-content:center; width:16px; height:16px; border:1.3px solid currentColor; border-radius:50%; font-size:11px; font-weight:600; line-height:1; box-sizing:border-box; font-family:inherit;">?</span>
                </button>
                <span x-show="show" x-cloak x-on:click.outside="show = false" style="position:absolute; bottom:calc(100% + 10px); left:50%; transform:translateX(-50%); width:300px; background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px; font-size:13px; font-weight:400; color:#1e293b; line-height:1.55; box-shadow:0 8px 24px rgba(15,23,42,0.1); z-index:30; letter-spacing:-0.01em;">
                    {{ $row['tooltip'] }}
                    <span style="position:absolute; top:100%; left:50%; transform:translateX(-50%) rotate(45deg); width:10px; height:10px; background:#fff; border-right:1px solid #e2e8f0; border-bottom:1px solid #e2e8f0; margin-top:-5px;"></span>
                </span>
            </span>
        </div>

        {{-- Security Issues --}}
        @php
            // Vendor-flagged security updates (Composer only - npm doesn't surface
            // a vendor `security` flag through any feed we read). When the OSV
            // status is "ok" but the marketplace has flagged a release, treat
            // that as a security advisory the user should see.
            $vendorOnly = (int) ($d['outdated']['vendor_security_updates_total'] ?? 0);
        @endphp
        @if($d['status'] === 'ok' || $d['status'] === 'vulnerable')
            <div style="margin-bottom:14px;">
                @if($d['status'] === 'ok' && $vendorOnly === 0)
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                        <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#64748b;">Security Issues</div>
                        <span style="display:inline-flex; align-items:center; font-size:12px; font-weight:500; color:#047857; background:#fff; border:1px solid #047857; padding:3px 10px; border-radius:5px;">No known vulnerabilities</span>
                    </div>
                @elseif($d['status'] === 'ok' && $vendorOnly > 0)
                    {{-- No OSV advisories, but the vendor has flagged at least one release as security.
                         Surface this so the Sentinel card matches the built-in updater's red badge. --}}
                    @php
                        $vendorPackages = array_values(array_filter(
                            $d['outdated']['packages'] ?? [],
                            fn($p) => ($p['security_source'] ?? null) === 'vendor'
                        ));
                    @endphp
                    <div x-data="{ open: false }">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                            <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#64748b;">Security Issues</div>
                            <button type="button" x-on:click="open = !open" aria-label="Toggle vendor security updates list" title="Statamic has marked at least one available update as a security release." style="display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:500; padding:3px 10px; border-radius:5px; color:#dc2626; background:#fff; border:1px solid #dc2626; cursor:pointer; font-family:inherit;">
                                <span>{{ $vendorOnly }} vendor security {{ $vendorOnly === 1 ? 'update' : 'updates' }}</span>
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" x-bind:style="{ transform: open ? 'rotate(180deg)' : null }" style="display:block; flex-shrink:0; transition:transform 0.15s ease;"><path d="m4 6 4 4 4-4"></path></svg>
                            </button>
                        </div>
                        <div x-show="open" x-cloak style="margin-top:8px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; overflow:hidden;">
                            @foreach($vendorPackages as $i => $pkg)
                                <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:6px 12px; {{ $i < count($vendorPackages) - 1 ? 'border-bottom:1px solid #e2e8f0;' : '' }}">
                                    <div style="display:flex; align-items:center; gap:6px; min-width:0;">
                                        <div style="font-size:13px; font-weight:600; color:#0f172a; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $pkg['name'] }}</div>
                                    </div>
                                    <span style="font-size:11px; font-weight:500; color:#0f172a; flex-shrink:0; font-variant-numeric:tabular-nums;">{{ $pkg['current'] }} → {{ $pkg['latest'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @else
                    @php
                        // by_package is computed in AuditService::queryOsv() at scan time;
                        // fall back to live grouping for snapshots cached before that field existed.
                        $byPackage = $d['by_package']
                            ?? \D3Creative\Sentinel\Services\AuditService::groupBySeverity($d['severities'] ?? []);

                        // dependency_parents maps a vulnerable transitive package to the
                        // direct dependency that pulls it in (empty on pre-nesting snapshots).
                        $parents = $d['dependency_parents'] ?? [];

                        // Group children under their parent; everything else is top-level,
                        // preserving the severity-sorted order of by_package.
                        $childrenOf = [];
                        foreach ($byPackage as $pkg) {
                            $parent = $parents[$pkg['name']] ?? null;
                            if ($parent !== null && $parent !== $pkg['name']) {
                                $childrenOf[$parent][] = $pkg;
                            }
                        }

                        $childNames = [];
                        foreach ($childrenOf as $kids) {
                            foreach ($kids as $k) { $childNames[$k['name']] = true; }
                        }

                        $vulnByName = [];
                        foreach ($byPackage as $pkg) { $vulnByName[$pkg['name']] = true; }

                        $topLevel = [];
                        foreach ($byPackage as $pkg) {
                            if (! isset($childNames[$pkg['name']])) {
                                $topLevel[] = $pkg;
                            }
                        }

                        // A resolved parent that isn't itself vulnerable needs a header-only
                        // row so its vulnerable children have something to nest under.
                        foreach (array_keys($childrenOf) as $parentName) {
                            if (! isset($vulnByName[$parentName]) && ! isset($childNames[$parentName])) {
                                $topLevel[] = ['name' => $parentName, 'highest' => null, 'count' => 0, '_header_only' => true];
                            }
                        }

                        // Flatten to an ordered row list (parent then its children) so the
                        // last-row border is trivial to compute across the nested groups.
                        $rows = [];
                        foreach ($topLevel as $pkg) {
                            $rows[] = ['kind' => ! empty($pkg['_header_only']) ? 'header' : 'parent', 'pkg' => $pkg];
                            foreach ($childrenOf[$pkg['name']] ?? [] as $child) {
                                $rows[] = ['kind' => 'child', 'pkg' => $child];
                            }
                        }
                    @endphp
                    <div x-data="{ open: false }">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                            <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#64748b;">Security Issues</div>
                            <button type="button" x-on:click="open = !open" aria-label="Toggle security issues list" style="display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:500; padding:3px 10px; border-radius:5px; color:#dc2626; background:#fff; border:1px solid #dc2626; cursor:pointer; font-family:inherit;">
                                <span>{{ $d['total_vulns'] }} security {{ $d['total_vulns'] === 1 ? 'issue' : 'issues' }}</span>
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" x-bind:style="{ transform: open ? 'rotate(180deg)' : null }" style="display:block; flex-shrink:0; transition:transform 0.15s ease;"><path d="m4 6 4 4 4-4"></path></svg>
                            </button>
                        </div>
                        <div x-show="open" x-cloak style="margin-top:8px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; overflow:hidden;">
                            @foreach($rows as $i => $row)
                                @php
                                    $pkg      = $row['pkg'];
                                    $isChild  = $row['kind'] === 'child';
                                    $isHeader = $row['kind'] === 'header';
                                    $vulns    = $pkg['vulns'] ?? [];
                                    $count    = $pkg['count'] ?? 0;
                                    // Every advisory lists inline on the package row; pre-CVE snapshots
                                    // lack `vulns` and fall through to the plain count+pill row.
                                    $hasVulns = ! $isHeader && ! empty($vulns);
                                    $border   = $i < count($rows) - 1 ? 'border-bottom:1px solid #e2e8f0;' : '';
                                    $pad      = $isChild ? 'padding:6px 12px 6px 32px;' : 'padding:6px 12px;';
                                    $cveLabel = fn($v) => $v['cve'] ?? $v['id'] ?? 'Advisory';
                                    // High/Critical advisories are highlighted red; the rest stay grey.
                                    $codeColour = fn($sev) => in_array(strtoupper((string) $sev), ['CRITICAL', 'HIGH']) ? '#dc2626' : '#64748b';
                                    // Fallback pill (pre-CVE snapshots) follows the same red-or-grey rule.
                                    $pillColour = fn($sev) => in_array(strtoupper((string) $sev), ['CRITICAL', 'HIGH']) ? '#dc2626' : '#475569';
                                @endphp
                                @if($hasVulns)
                                    <div style="display:flex; align-items:center; flex-wrap:wrap; gap:5px 8px; {{ $pad }} {{ $border }}">
                                        <span style="display:inline-flex; align-items:center; gap:6px; margin-right:2px; min-width:0;">
                                            @if($isChild)<span style="color:#94a3b8; font-size:13px; line-height:1; flex-shrink:0;">&#8627;</span>@endif
                                            <span style="font-size:13px; font-weight:{{ $isChild ? 500 : 600 }}; color:{{ $isChild ? '#334155' : '#0f172a' }};">{{ $pkg['name'] }}</span>
                                        </span>
                                        @foreach($vulns as $vi => $v)
                                            <span style="white-space:nowrap;"><a href="{{ $v['url'] }}" target="_blank" rel="noopener" class="d3-sentinel-cve" style="font-size:11px; font-weight:500; color:{{ $codeColour($v['severity']) }}; text-transform:uppercase; font-variant-numeric:tabular-nums;">{{ $cveLabel($v) }}</a>{{ $vi < count($vulns) - 1 ? ',' : '' }}</span>
                                        @endforeach
                                    </div>
                                @else
                                    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; {{ $pad }} {{ $border }}">
                                        @include('statamic-sentinel::utilities._security_row_label')
                                        @unless($isHeader)
                                            <span style="display:inline-flex; align-items:center; gap:8px; flex-shrink:0;">
                                                @if ($count > 1)
                                                    <span style="font-size:11px; font-weight:500; color:#64748b;">{{ $count }} issues</span>
                                                @endif
                                                <span style="display:inline-flex; align-items:center; font-size:11px; font-weight:500; padding:1px 7px; border-radius:4px; color:{{ $pillColour($pkg['highest']) }}; background:#fff; border:1px solid {{ $pillColour($pkg['highest']) }};">{{ ucfirst(strtolower($pkg['highest'])) }}</span>
                                            </span>
                                        @endunless
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        @endif

        {{-- Updates Available --}}
        @if(!empty($d['outdated']) && !empty($d['outdated']['packages']))
            @php $packages = $d['outdated']['packages']; @endphp
            <div x-data="{ open: false }" style="border-top:1px solid #e2e8f0; padding-top:12px;">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                    <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#64748b;">Updates Available</div>
                    <button type="button" x-on:click="open = !open" aria-label="Toggle updates list" style="display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:500; padding:3px 10px; border-radius:5px; color:#0f172a; background:#fff; border:1px solid #0f172a; cursor:pointer; font-family:inherit;">
                        @php $blockedCount = collect($packages)->where('blocked', true)->count(); @endphp
                        <span>{{ count($packages) }} {{ count($packages) === 1 ? 'update' : 'updates' }} available{{ $blockedCount ? ' (' . $blockedCount . ' blocked)' : '' }}</span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" x-bind:style="{ transform: open ? 'rotate(180deg)' : null }" style="display:block; flex-shrink:0; transition:transform 0.15s ease;">
                            <path d="m4 6 4 4 4-4" />
                        </svg>
                    </button>
                </div>
                <div x-show="open" x-cloak style="margin-top:8px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; overflow:hidden;">
                    @foreach($packages as $i => $pkg)
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:6px 12px; {{ $i < count($packages) - 1 ? 'border-bottom:1px solid #e2e8f0;' : '' }}">
                            <div style="display:flex; align-items:center; gap:6px; min-width:0;">
                                <div style="font-size:13px; font-weight:600; color:#0f172a; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $pkg['name'] }}</div>
                                @if(!empty($pkg['blocked']))
                                    <span title="Held by npm's min-release-age guard until {{ $pkg['blocked_until'] }}"
                                          style="display:inline-flex; align-items:center; flex-shrink:0; font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; padding:1px 7px; border-radius:4px; color:#64748b; background:#fff; border:1px solid #cbd5e1; cursor:default;">Blocked</span>
                                @endif
                            </div>
                            <span style="display:inline-flex; align-items:center; gap:8px; flex-shrink:0;">
                                @if(!empty($pkg['blocked']))
                                    <span style="font-size:11px; color:#64748b; font-variant-numeric:tabular-nums;">available in {{ $pkg['available_in_days'] }} {{ $pkg['available_in_days'] === 1 ? 'day' : 'days' }}</span>
                                @endif
                                <span style="font-size:11px; font-weight:500; color:#0f172a; font-variant-numeric:tabular-nums;">{{ $pkg['current'] }} → {{ $pkg['latest'] }}</span>
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        @elseif(!empty($d['outdated']) && in_array($d['status'], ['ok', 'vulnerable']))
            <div style="border-top:1px solid #e2e8f0; padding-top:12px;">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                    <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#64748b;">Updates Available</div>
                    <span style="display:inline-flex; align-items:center; font-size:12px; font-weight:500; padding:3px 10px; border-radius:5px; color:#047857; background:#fff; border:1px solid #047857;">All packages up to date</span>
                </div>
            </div>
        @endif

    </div>
    @endforeach

        </div>

        @if ($isSuper)
        {{-- History tab --}}
        <div x-show="tab === 'history'" role="tabpanel" x-cloak>

            @if (empty($history))

                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:48px 24px; text-align:center;">
                    <p style="font-size:15px; font-weight:600; color:#0f172a; margin:0 0 6px 0;">No update history yet</p>
                    <p style="font-size:13px; color:#64748b; margin:0; line-height:1.55; max-width:420px; margin-left:auto; margin-right:auto;">Entries will appear here as your Statamic, Laravel, PHP or package versions change. The next change will be the first row.</p>
                </div>

            @else

                @php
                    $versionCols = [
                        'statamic' => 'Statamic',
                        'laravel'  => 'Laravel',
                        'php'      => 'PHP',
                    ];

                    $ecosystemLines = function (int $vulns, int $outdated) {
                        $lines = [];
                        if ($vulns > 0) {
                            $lines[] = $vulns . ' security ' . \Illuminate\Support\Str::plural('issue', $vulns);
                        }
                        if ($outdated > 0) {
                            $lines[] = $outdated . ' ' . \Illuminate\Support\Str::plural('update', $outdated) . ' available';
                        }
                        if (empty($lines)) {
                            $lines[] = 'Up to date';
                        }
                        return $lines;
                    };
                @endphp

                <div style="border:1px solid #e2e8f0; border-radius:8px; overflow:hidden;">
                    <div style="overflow-x:auto;">
                        <table style="width:100%; border-collapse:collapse; font-size:13px;">
                            <thead>
                                <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0;">
                                    <th style="text-align:left; padding:10px 14px; font-weight:600; color:#475569; white-space:nowrap;">Date</th>
                                    @foreach ($versionCols as $key => $label)
                                        <th style="text-align:left; padding:10px 14px; font-weight:600; color:#475569; white-space:nowrap;">{{ $label }}</th>
                                    @endforeach
                                    <th style="text-align:left; padding:10px 14px; font-weight:600; color:#475569; white-space:nowrap;">Composer</th>
                                    <th style="text-align:left; padding:10px 14px; font-weight:600; color:#475569; white-space:nowrap;">npm</th>
                                    <th style="text-align:right; padding:10px 14px; font-weight:600; color:#475569; white-space:nowrap;">&nbsp;</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($history as $i => $entry)
                                    @php
                                        try {
                                            $recordedAt = \Carbon\Carbon::parse($entry['recorded_at'])->format('j M Y, H:i');
                                        } catch (\Throwable $e) {
                                            $recordedAt = $entry['recorded_at'] ?? '-';
                                        }

                                        $composerLines = $ecosystemLines(
                                            (int) ($entry['composer_vulns']    ?? 0),
                                            (int) ($entry['composer_outdated'] ?? 0)
                                        );
                                        $npmLines = $ecosystemLines(
                                            (int) ($entry['npm_vulns']    ?? 0),
                                            (int) ($entry['npm_outdated'] ?? 0)
                                        );

                                        $deleteKey = $entry['id'] ?? ($entry['recorded_at'] ?? '');
                                        $confirmText = $loop->index < 2
                                            ? "Delete this snapshot? The next Update Report will compare against an earlier snapshot."
                                            : "Delete this snapshot? This won't affect the audit cache, only the history row.";
                                    @endphp
                                    <tr @if (! $loop->last) style="border-bottom:1px solid #f1f5f9;" @endif>
                                        <td style="padding:10px 14px; color:#475569; white-space:nowrap;">{{ $recordedAt }}</td>
                                        @foreach ($versionCols as $key => $label)
                                            <td style="padding:10px 14px; white-space:nowrap; color:#0f172a;">{{ $entry[$key] ?? '-' }}</td>
                                        @endforeach
                                        @foreach ([$composerLines, $npmLines] as $lines)
                                            <td style="padding:10px 14px; white-space:nowrap; color:#0f172a; line-height:1.45;">
                                                @foreach ($lines as $line)
                                                    <div>{{ $line }}</div>
                                                @endforeach
                                            </td>
                                        @endforeach
                                        <td style="padding:10px 14px; text-align:right; white-space:nowrap;">
                                            @if ($deleteKey !== '')
                                                @include('statamic-sentinel::utilities._delete_row_button', [
                                                    'url'     => cp_route('d3-sentinel.history.actions.run'),
                                                    'handle'  => 'delete_history_entry',
                                                    'id'      => $deleteKey,
                                                    'confirm' => $confirmText,
                                                ])
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <p style="font-size:12px; color:#64748b; margin:10px 2px 0 2px;">Newest first. Retained for {{ \D3Creative\Sentinel\Services\HistoryService::RETENTION_DAYS }} days.</p>

            @endif

        </div>

        {{-- Status Report tab --}}
        <div x-show="tab === 'status-report'" role="tabpanel" x-cloak>
            <div x-data="{
                    sending: false,
                    state: 'idle',
                    message: '',
                    send(form) {
                        this.sending = true;
                        this.state = 'idle';
                        this.message = '';
                        fetch(form.action, {
                            method: 'POST',
                            body: new FormData(form),
                            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                        })
                        .then(res => res.json().then(body => ({ ok: res.ok, body })))
                        .then(res => {
                            this.sending = false;
                            this.state = res.ok ? 'success' : 'error';
                            this.message = res.body.message;
                            if (res.ok) setTimeout(() => { this.state = 'idle'; this.message = ''; }, 4000);
                        })
                        .catch(() => {
                            this.sending = false;
                            this.state = 'error';
                            this.message = 'Something went wrong. Please try again.';
                        });
                    }
                 }"
                 style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px;">
                <div style="display:flex; align-items:baseline; justify-content:space-between; gap:12px; margin-bottom:10px;">
                    <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#64748b;">Email status report</div>
                    <div style="font-size:11px; color:#64748b;">Snapshot of current versions, vulnerabilities, and updates</div>
                </div>
                <form action="{{ route('statamic.cp.d3-sentinel.send-report') }}"
                      method="POST"
                      x-on:submit.prevent="send($event.target)"
                      style="display:flex; gap:8px; align-items:center;">
                    @csrf
                    <input type="text"
                           name="email"
                           value="{{ $statusEmail }}"
                           required
                           placeholder="email@example.com, another@example.com"
                           style="flex:1; font-size:13px; padding:7px 12px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; color:#1e293b; outline:none; min-width:0;">
                    <button type="button"
                            x-on:click="$dispatch('sentinel-preview-open', { url: @js(route('statamic.cp.d3-sentinel.preview-report')), title: 'Status report preview' })"
                            style="flex-shrink:0; font-size:13px; font-weight:600; color:#0f172a; background:#fff; border:1px solid #e2e8f0; padding:7px 12px; border-radius:6px; cursor:pointer; white-space:nowrap;">
                        Preview
                    </button>
                    <button type="submit"
                            x-bind:disabled="sending"
                            x-bind:style="{ background: state === 'success' ? '#047857' : (state === 'error' ? '#ef4444' : '#0f172a') }"
                            style="flex-shrink:0; font-size:13px; font-weight:600; color:#fff; background:#0f172a; border:none; padding:7px 14px; border-radius:6px; cursor:pointer; white-space:nowrap;">
                        <span x-show="sending" x-cloak style="display:inline-flex; align-items:center; gap:6px;">
                            <span aria-hidden="true" style="display:inline-block; font-size:14px; line-height:1; transform-origin:center; animation:sentinel-spin 1s linear infinite;">↻</span>
                            Sending…
                        </span>
                        <span x-show="!sending && state === 'success'" x-cloak>✓ Sent</span>
                        <span x-show="!sending && state === 'error'" x-cloak>✕ Failed</span>
                        <span x-show="!sending && state === 'idle'">Send Status Report</span>
                    </button>
                </form>
                <div x-show="message" x-cloak
                     x-bind:style="{ color: state === 'success' ? '#047857' : '#ef4444' }"
                     style="font-size:13px; margin-top:8px;"
                     x-text="message"></div>
            </div>

            @include('statamic-sentinel::utilities._schedule_card', [
                'key'    => 'status_report',
                'config' => $schedule['status_report'],
            ])

            @include('statamic-sentinel::utilities._sent_list', [
                'kind'    => 'status',
                'entries' => $sent_status,
            ])
        </div>

        {{-- Update Report tab --}}
        <div x-show="tab === 'update-report'" role="tabpanel" x-cloak>
            <div x-data="{
                    sending: false,
                    state: 'idle',
                    message: '',
                    canForce: false,
                    send(form, force = false) {
                        this.sending = true;
                        this.state = 'idle';
                        this.message = '';
                        this.canForce = false;
                        const data = new FormData(form);
                        if (force) data.set('force', '1');
                        fetch(form.action, {
                            method: 'POST',
                            body: data,
                            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                        })
                        .then(res => res.json().then(body => ({ ok: res.ok, body })))
                        .then(res => {
                            this.sending = false;
                            this.canForce = res.body.can_force === true;
                            if (res.ok) {
                                this.state = 'success';
                            } else if (this.canForce) {
                                this.state = 'notice';
                            } else {
                                this.state = 'error';
                            }
                            this.message = res.body.message;
                            if (res.ok) setTimeout(() => { this.state = 'idle'; this.message = ''; this.canForce = false; }, 4000);
                        })
                        .catch(() => {
                            this.sending = false;
                            this.state = 'error';
                            this.message = 'Something went wrong. Please try again.';
                        });
                    }
                 }"
                 style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px;">
                <div style="display:flex; align-items:baseline; justify-content:space-between; gap:12px; margin-bottom:10px;">
                    <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#64748b;">Email update report</div>
                    <div style="font-size:11px; color:#64748b;">Diff vs. previous snapshot</div>
                </div>
                <form x-ref="form"
                      action="{{ route('statamic.cp.d3-sentinel.send-update-report') }}"
                      method="POST"
                      x-on:submit.prevent="send($refs.form)"
                      style="display:flex; gap:8px; align-items:center;">
                    @csrf
                    <input type="text"
                           name="email"
                           value="{{ $updateEmail }}"
                           required
                           placeholder="email@example.com, another@example.com"
                           style="flex:1; font-size:13px; padding:7px 12px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; color:#1e293b; outline:none; min-width:0;">
                    <button type="button"
                            x-on:click="$dispatch('sentinel-preview-open', { url: @js(route('statamic.cp.d3-sentinel.preview-update-report')), title: 'Update report preview' })"
                            style="flex-shrink:0; font-size:13px; font-weight:600; color:#0f172a; background:#fff; border:1px solid #e2e8f0; padding:7px 12px; border-radius:6px; cursor:pointer; white-space:nowrap;">
                        Preview
                    </button>
                    <button type="submit"
                            x-bind:disabled="sending"
                            x-bind:style="{ background: state === 'success' ? '#047857' : (state === 'notice' ? '#f59e0b' : (state === 'error' ? '#ef4444' : '#0f172a')) }"
                            style="flex-shrink:0; font-size:13px; font-weight:600; color:#fff; background:#0f172a; border:none; padding:7px 14px; border-radius:6px; cursor:pointer; white-space:nowrap;">
                        <span x-show="sending" x-cloak style="display:inline-flex; align-items:center; gap:6px;">
                            <span aria-hidden="true" style="display:inline-block; font-size:14px; line-height:1; transform-origin:center; animation:sentinel-spin 1s linear infinite;">↻</span>
                            Sending…
                        </span>
                        <span x-show="!sending && state === 'success'" x-cloak>✓ Sent</span>
                        <span x-show="!sending && state === 'notice'" x-cloak>Hang on</span>
                        <span x-show="!sending && state === 'error'" x-cloak>✕ Failed</span>
                        <span x-show="!sending && state === 'idle'">Send Update Report</span>
                    </button>
                </form>
                <div x-show="message" x-cloak
                     style="display:flex; align-items:center; gap:10px; font-size:13px; margin-top:8px;">
                    <span x-text="message" x-bind:style="{ color: state === 'success' ? '#047857' : (state === 'notice' ? '#f59e0b' : '#ef4444') }"></span>
                    <button type="button"
                            x-show="canForce && !sending"
                            x-on:click="send($refs.form, true)"
                            style="font-size:12px; font-weight:600; color:#0f172a; background:#fff; border:1px solid #e2e8f0; padding:3px 10px; border-radius:5px; cursor:pointer; font-family:inherit;">
                        Send anyway
                    </button>
                </div>
            </div>

            @include('statamic-sentinel::utilities._sent_list', [
                'kind'    => 'update',
                'entries' => $sent_update,
            ])
        </div>

        {{-- Maintenance Report tab --}}
        <div x-show="tab === 'maintenance-report'" role="tabpanel" x-cloak>

            {{-- Plan details --}}
            <div x-data="{
                    saving: false,
                    state: 'idle',
                    message: '',
                    save(form) {
                        this.saving = true;
                        this.state = 'idle';
                        this.message = '';
                        fetch(form.action, {
                            method: 'POST',
                            body: new FormData(form),
                            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                        })
                        .then(res => res.json().then(body => ({ ok: res.ok, body })))
                        .then(res => {
                            this.saving = false;
                            this.state = res.ok ? 'success' : 'error';
                            this.message = res.body.message;
                            if (res.ok) setTimeout(() => { this.state = 'idle'; this.message = ''; }, 4000);
                        })
                        .catch(() => {
                            this.saving = false;
                            this.state = 'error';
                            this.message = 'Something went wrong. Please try again.';
                        });
                    }
                 }"
                 style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px; margin-bottom:12px;">
                <div style="display:flex; align-items:baseline; justify-content:space-between; gap:12px; margin-bottom:12px;">
                    <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#64748b;">Maintenance plan details</div>
                    <div style="font-size:11px; color:#64748b;">Shown in the report intro</div>
                </div>
                <form action="{{ route('statamic.cp.d3-sentinel.save-maintenance-plan') }}"
                      method="POST"
                      x-on:submit.prevent="save($event.target)">
                    @csrf
                    <div style="display:flex; flex-wrap:wrap; gap:12px;">
                        <label style="flex:1 1 100%; min-width:0; font-size:12px; color:#475569;">
                            <span style="display:block; margin-bottom:4px; font-weight:600; color:#0f172a;">Plan name</span>
                            <input type="text" name="plan_name" value="{{ $plan['plan_name'] ?? '' }}" placeholder="e.g. Gold Care Plan"
                                   style="width:100%; box-sizing:border-box; font-size:13px; padding:7px 12px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; color:#1e293b; outline:none;">
                        </label>
                        <label style="flex:1 1 160px; min-width:0; font-size:12px; color:#475569;">
                            <span style="display:block; margin-bottom:4px; font-weight:600; color:#0f172a;">Start date</span>
                            <input type="date" name="start_date" value="{{ $plan['start_date'] ?? '' }}"
                                   style="width:100%; box-sizing:border-box; font-size:13px; padding:7px 12px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; color:#1e293b; outline:none;">
                        </label>
                        <label style="flex:1 1 160px; min-width:0; font-size:12px; color:#475569;">
                            <span style="display:block; margin-bottom:4px; font-weight:600; color:#0f172a;">Expiry date</span>
                            <input type="date" name="expiry_date" value="{{ $plan['expiry_date'] ?? '' }}"
                                   style="width:100%; box-sizing:border-box; font-size:13px; padding:7px 12px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; color:#1e293b; outline:none;">
                        </label>
                    </div>
                    <div style="display:flex; flex-wrap:wrap; align-items:center; gap:14px; margin-top:12px;">
                        <label style="display:inline-flex; align-items:center; gap:8px; font-size:13px; color:#0f172a; cursor:pointer;">
                            <input type="checkbox" name="show_reminder" value="1" {{ ($plan['show_reminder'] ?? true) ? 'checked' : '' }}
                                   style="width:15px; height:15px; cursor:pointer;">
                            Include renewal reminder line
                        </label>
                        <label style="display:inline-flex; align-items:center; gap:8px; font-size:13px; color:#475569;">
                            <input type="number" name="reminder_days" value="{{ $plan['reminder_days'] ?? 30 }}" min="1" max="365"
                                   style="width:64px; font-size:13px; padding:6px 8px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; color:#1e293b; outline:none; font-variant-numeric:tabular-nums;">
                            days before expiry
                        </label>
                        <button type="submit"
                                x-bind:disabled="saving"
                                x-bind:style="{ background: state === 'success' ? '#047857' : (state === 'error' ? '#ef4444' : '#0f172a') }"
                                style="margin-left:auto; flex-shrink:0; font-size:13px; font-weight:600; color:#fff; background:#0f172a; border:none; padding:7px 16px; border-radius:6px; cursor:pointer; white-space:nowrap;">
                            <span x-show="saving" x-cloak>Saving…</span>
                            <span x-show="!saving && state === 'success'" x-cloak>✓ Saved</span>
                            <span x-show="!saving && state === 'error'" x-cloak>✕ Failed</span>
                            <span x-show="!saving && state === 'idle'">Save Plan Details</span>
                        </button>
                    </div>
                    <div style="font-size:11px; color:#64748b; margin-top:10px; line-height:1.5;">The reminder line is text only - Sentinel does not send a reminder email. Leave it unchecked if you handle renewals another way.</div>
                    <div x-show="message" x-cloak
                         x-bind:style="{ color: state === 'success' ? '#047857' : '#ef4444' }"
                         style="font-size:13px; margin-top:8px;"
                         x-text="message"></div>
                </form>
            </div>

            {{-- Send --}}
            <div x-data="{
                    sending: false,
                    state: 'idle',
                    message: '',
                    send(form) {
                        this.sending = true;
                        this.state = 'idle';
                        this.message = '';
                        fetch(form.action, {
                            method: 'POST',
                            body: new FormData(form),
                            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                        })
                        .then(res => res.json().then(body => ({ ok: res.ok, body })))
                        .then(res => {
                            this.sending = false;
                            this.state = res.ok ? 'success' : 'error';
                            this.message = res.body.message;
                            if (res.ok) setTimeout(() => { this.state = 'idle'; this.message = ''; }, 4000);
                        })
                        .catch(() => {
                            this.sending = false;
                            this.state = 'error';
                            this.message = 'Something went wrong. Please try again.';
                        });
                    }
                 }"
                 style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px;">
                <div style="display:flex; align-items:baseline; justify-content:space-between; gap:12px; margin-bottom:10px;">
                    <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#64748b;">Email maintenance report</div>
                    <div style="font-size:11px; color:#64748b;">Update counts since the plan started</div>
                </div>
                <form action="{{ route('statamic.cp.d3-sentinel.send-maintenance-report') }}"
                      method="POST"
                      x-on:submit.prevent="send($event.target)"
                      style="display:flex; gap:8px; align-items:center;">
                    @csrf
                    <input type="text"
                           name="email"
                           value="{{ $maintenanceEmail }}"
                           required
                           placeholder="email@example.com, another@example.com"
                           style="flex:1; font-size:13px; padding:7px 12px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; color:#1e293b; outline:none; min-width:0;">
                    <button type="button"
                            x-on:click="$dispatch('sentinel-preview-open', { url: @js(route('statamic.cp.d3-sentinel.preview-maintenance-report')), title: 'Maintenance report preview' })"
                            style="flex-shrink:0; font-size:13px; font-weight:600; color:#0f172a; background:#fff; border:1px solid #e2e8f0; padding:7px 12px; border-radius:6px; cursor:pointer; white-space:nowrap;">
                        Preview
                    </button>
                    <button type="submit"
                            x-bind:disabled="sending"
                            x-bind:style="{ background: state === 'success' ? '#047857' : (state === 'error' ? '#ef4444' : '#0f172a') }"
                            style="flex-shrink:0; font-size:13px; font-weight:600; color:#fff; background:#0f172a; border:none; padding:7px 14px; border-radius:6px; cursor:pointer; white-space:nowrap;">
                        <span x-show="sending" x-cloak style="display:inline-flex; align-items:center; gap:6px;">
                            <span aria-hidden="true" style="display:inline-block; font-size:14px; line-height:1; transform-origin:center; animation:sentinel-spin 1s linear infinite;">↻</span>
                            Sending…
                        </span>
                        <span x-show="!sending && state === 'success'" x-cloak>✓ Sent</span>
                        <span x-show="!sending && state === 'error'" x-cloak>✕ Failed</span>
                        <span x-show="!sending && state === 'idle'">Send Maintenance Report</span>
                    </button>
                </form>
                <div x-show="message" x-cloak
                     x-bind:style="{ color: state === 'success' ? '#047857' : '#ef4444' }"
                     style="font-size:13px; margin-top:8px;"
                     x-text="message"></div>
            </div>

            <div style="font-size:11px; color:#64748b; margin-top:10px; line-height:1.5;">Counts reflect changes recorded by Sentinel scans - run a scan (or hit Refresh) after each maintenance session for complete figures.</div>

            @include('statamic-sentinel::utilities._sent_list', [
                'kind'    => 'maintenance',
                'entries' => $sent_maintenance,
            ])
        </div>

        {{-- Content Freeze tab --}}
        <div x-show="tab === 'content-freeze'" role="tabpanel" x-cloak>
            @include('statamic-sentinel::utilities._content_freeze', [
                'freeze_service' => $freeze,
                'freeze_current' => $freeze_current,
                'freeze_history' => $freeze_history,
            ])
        </div>
        @endif

    </div>
    {{-- /Tabs --}}

    {{-- Footer --}}
    <div style="display:flex; align-items:center; justify-content:flex-start; margin-top:16px; padding-top:14px;">
        <p style="font-size:12px; color:rgb(63 63 71); margin:0; letter-spacing:-0.01em;">
            Sentinel{!! $sentinelBrandSuffix !!}. Platform and dependency audits for Statamic sites.
        </p>
    </div>

    @if ($isSuper)
    {{-- Email preview modal (shared between Status Report and Update Report tabs) --}}
    <div x-data="{ src: '', title: '' }"
         x-on:sentinel-preview-open.window="
            title = $event.detail.title;
            src = $event.detail.url;
            $refs.dlg.showModal();
         "
         x-cloak>
        <dialog x-ref="dlg"
                x-on:click.self="$refs.dlg.close()"
                x-on:close="src = ''"
                style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); width:min(960px,95vw); height:min(820px,90vh); margin:0; padding:0; border:none; border-radius:10px; overflow:hidden; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
            <div style="display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border-bottom:1px solid #e2e8f0; background:#f8fafc;">
                <strong style="font-size:13px; color:#0f172a;" x-text="title"></strong>
                <button type="button"
                        x-on:click="$refs.dlg.close()"
                        style="font-size:12px; font-weight:600; color:#0f172a; background:#fff; border:1px solid #e2e8f0; padding:4px 10px; border-radius:5px; cursor:pointer; font-family:inherit;">
                    Close
                </button>
            </div>
            <iframe x-bind:src="src"
                    title="Email preview"
                    style="display:block; width:100%; height:calc(100% - 41px); border:none; background:#fff;"></iframe>
        </dialog>
    </div>

    {{-- Confirm modal (shared by row-delete buttons in History and Sent Emails tables) --}}
    <div x-data="{
            message: '',
            confirmLabel: 'Delete',
            onConfirm: null
         }"
         x-on:sentinel-confirm-open.window="
            message      = $event.detail.message      || 'Are you sure?';
            confirmLabel = $event.detail.confirmLabel || 'Delete';
            onConfirm    = $event.detail.onConfirm    || null;
            $refs.cdlg.showModal();
         "
         x-cloak>
        <dialog x-ref="cdlg"
                x-on:click.self="$refs.cdlg.close()"
                x-on:close="onConfirm = null"
                style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); width:min(440px,90vw); margin:0; padding:0; border:none; border-radius:10px; overflow:hidden; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
            <div style="padding:20px 24px;">
                <h2 style="font-size:15px; font-weight:600; color:#0f172a; margin:0 0 8px 0;">Confirm delete</h2>
                <p style="font-size:13px; color:#475569; margin:0; line-height:1.55;" x-text="message"></p>
            </div>
            <div style="display:flex; align-items:center; justify-content:flex-end; gap:8px; padding:12px 24px; background:#f8fafc; border-top:1px solid #e2e8f0;">
                <button type="button"
                        x-on:click="$refs.cdlg.close()"
                        style="font-size:13px; font-weight:600; color:#0f172a; background:#fff; border:1px solid #e2e8f0; padding:7px 14px; border-radius:6px; cursor:pointer; font-family:inherit;">
                    Cancel
                </button>
                <button type="button"
                        x-on:click="(() => {
                            const cb = onConfirm;
                            $refs.cdlg.close();
                            if (cb) cb();
                        })()"
                        x-text="confirmLabel"
                        style="font-size:13px; font-weight:600; color:#fff; background:#dc2626; border:none; padding:7px 14px; border-radius:6px; cursor:pointer; font-family:inherit;"></button>
            </div>
        </dialog>
    </div>
    @endif

@endif

</div>

@endsection
