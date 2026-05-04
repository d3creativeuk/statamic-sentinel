{{--
    D3 Creative — Sentinel Utility (full report)
    Mirrors the widget data, expanded: full vulnerability list + full outdated list.
--}}

@extends('statamic::layout')

@section('title', 'Sentinel')

@section('content')

<div x-data x-init="$nextTick(() => window.scrollTo({ top: 0, behavior: 'instant' }))" style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-size: 14px; color: #1e293b;">

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
           x-on:click.prevent="$el.querySelector('[data-sentinel-label]').textContent = 'Scanning…'; $el.querySelector('[data-sentinel-icon]').style.animation = 'sentinel-spin 1s linear infinite'; requestAnimationFrame(() => requestAnimationFrame(() => location.href = $el.href))"
           href="?d3_refresh=1"
           style="display:inline-flex; align-items:center; justify-content:center; gap:8px; white-space:nowrap; font-weight:600; cursor:pointer; text-decoration:none; color:#fff; background:#0f172a; padding:0 18px; height:38px; font-size:13px; line-height:1.25; border-radius:8px;">
            <span data-sentinel-label>Scan Now</span>
            <svg data-sentinel-icon xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="14" height="14" style="flex-shrink:0;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
            </svg>
        </a>
        <p style="font-size:11px; color:#94a3b8; margin:14px 0 0 0;">Takes 10–20 seconds.</p>
    </div>
    <div style="display:flex; align-items:center; justify-content:flex-start; margin-top:16px; padding-top:14px;">
        <p style="font-size:12px; color:rgb(63 63 71); margin:0; letter-spacing:-0.01em;">
            Sentinel by <a href="https://d3creative.uk/sentinel" target="_blank" style="color:rgb(63 63 71); text-decoration:underline;">D3 Creative</a>. Security and update alerts for Statamic sites.
        </p>
    </div>

@else

@php
    extract($audit);

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
               x-on:click.prevent="$el.querySelector('[data-sentinel-label]').textContent = 'Scanning…'; $el.querySelector('[data-sentinel-icon]').style.animation = 'sentinel-spin 1s linear infinite'; requestAnimationFrame(() => requestAnimationFrame(() => location.href = $el.href))"
               href="?d3_refresh=1"
               title="Refresh audit results"
               style="display:inline-flex; align-items:center; gap:4px; font-size:12px; color:rgb(63 63 71); text-decoration:none;">
                <span data-sentinel-icon aria-hidden="true" style="display:inline-block; font-size:14px; line-height:1; flex-shrink:0; transform-origin:center;">↻</span>
                <span data-sentinel-label>Refresh</span>
            </a>
        </div>
    </header>

    {{-- Tabs --}}
    <div x-data="{ tab: 'current' }">

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
        </div>

        {{-- Current tab --}}
        <div x-show="tab === 'current'" role="tabpanel">

    {{-- Version list: Statamic / Laravel / PHP --}}
    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; margin-bottom:12px;">
        @foreach ([
            ['label' => 'Statamic', 'version' => $statamic['current'], 'latest' => $statamic['latest'] ?? null, 'status' => $statamic['status'], 'security' => $statamic['security_update_available'] ?? false],
            ['label' => 'Laravel',  'version' => $laravel['version'],  'latest' => $laravel['latest']  ?? null, 'status' => $laravel['status'],  'security' => $laravel['security_update_available']  ?? false],
            ['label' => 'PHP',      'version' => $php['version'],      'latest' => $php['latest']      ?? null, 'status' => $php['status'],      'security' => false],
        ] as $card)
            @php
                $outdated   = ! empty($card['latest']) && version_compare($card['version'], $card['latest'], '<');
                $isEol      = $card['status'] === 'eol';
                $pillColour = ($card['security'] || $isEol) ? '#dc2626' : ($outdated ? '#3b82f6' : '#10b981');
            @endphp
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:8px 14px; {{ ! $loop->last ? 'border-bottom:1px solid #e2e8f0;' : '' }}">
                <span style="font-size:13px; font-weight:600; color:#0f172a;">{{ $card['label'] }}</span>
                <span style="display:inline-flex; align-items:center; font-size:11px; font-weight:500; padding:1px 7px; border-radius:4px; color:{{ $pillColour }}; background:#fff; border:1px solid {{ $pillColour }}; flex-shrink:0; font-variant-numeric:tabular-nums;">
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
    </div>

    {{-- Package audit sections --}}
    @foreach ([
        ['label' => 'Composer packages', 'data' => $composer, 'tooltip' => "PHP packages that power your site's backend — including Statamic itself, Laravel (the framework it runs on), and any installed add-ons. Keeping these up to date is important for security and stability."],
        ['label' => 'npm packages',      'data' => $npm,      'tooltip' => "JavaScript packages used to build your site's frontend — things like scripts and styles that run in your visitors' browsers. Updates often include security fixes and improvements."],
    ] as $row)
    @php $d = $row['data']; @endphp
    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px; margin-bottom:12px;">

        <div style="display:flex; align-items:center; gap:6px; margin-bottom:12px;">
            <span style="font-weight:600; font-size:14px;">
                @if($d['status'] === 'unavailable')
                    {{ $row['label'] }} — lock file not found
                @elseif($d['status'] === 'error')
                    {{ $row['label'] }} — <span style="color:#ef4444;">⚠ check failed</span>
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
        @if($d['status'] === 'ok' || $d['status'] === 'vulnerable')
            <div style="margin-bottom:14px;">
                @if($d['status'] === 'ok')
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                        <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#94a3b8;">Security Issues</div>
                        <span style="display:inline-flex; align-items:center; font-size:12px; font-weight:500; color:#10b981; background:#fff; border:1px solid #10b981; padding:3px 10px; border-radius:5px;">No known vulnerabilities</span>
                    </div>
                @else
                    @php
                        $sevRank = ['CRITICAL' => 5, 'HIGH' => 4, 'MEDIUM' => 3, 'LOW' => 2, 'UNKNOWN' => 1];

                        // Group vulns by package, tracking the highest severity seen
                        // for that package so a single-row badge can summarise it.
                        $byPackage = [];
                        foreach (['CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'UNKNOWN'] as $sev) {
                            foreach ($d['severities'][$sev]['vulns'] ?? [] as $v) {
                                $name = $v['package'];
                                if (! isset($byPackage[$name])) {
                                    $byPackage[$name] = ['name' => $name, 'highest' => $sev, 'count' => 0];
                                }
                                if ($sevRank[$sev] > $sevRank[$byPackage[$name]['highest']]) {
                                    $byPackage[$name]['highest'] = $sev;
                                }
                                $byPackage[$name]['count']++;
                            }
                        }

                        uasort($byPackage, function($a, $b) use ($sevRank) {
                            $cmp = $sevRank[$b['highest']] - $sevRank[$a['highest']];
                            return $cmp !== 0 ? $cmp : strcmp($a['name'], $b['name']);
                        });
                        $byPackage = array_values($byPackage);
                    @endphp
                    <div x-data="{ open: false }">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                            <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#94a3b8;">Security Issues</div>
                            <button type="button" x-on:click="open = !open" aria-label="Toggle security issues list" style="display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:500; padding:3px 10px; border-radius:5px; color:#dc2626; background:#fff; border:1px solid #dc2626; cursor:pointer; font-family:inherit;">
                                <span>{{ count($byPackage) }} {{ count($byPackage) === 1 ? 'package' : 'packages' }} affected</span>
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" x-bind:style="{ transform: open ? 'rotate(180deg)' : null }" style="display:block; flex-shrink:0; transition:transform 0.15s ease;"><path d="m4 6 4 4 4-4"></path></svg>
                            </button>
                        </div>
                        <div x-show="open" x-cloak style="margin-top:8px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; overflow:hidden;">
                            @foreach($byPackage as $i => $pkg)
                                @php
                                    $sevColour  = $severityColour($pkg['highest']);
                                    $lockColour = in_array($pkg['highest'], ['CRITICAL', 'HIGH']) ? '#dc2626' : '#94a3b8';
                                @endphp
                                <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:6px 12px; {{ $i < count($byPackage) - 1 ? 'border-bottom:1px solid #e2e8f0;' : '' }}">
                                    <div style="display:flex; align-items:center; gap:6px; min-width:0;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 20 20" fill="{{ $lockColour }}" style="flex-shrink:0;"><title>Security issue</title><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1zm3 8V5.5a3 3 0 1 0-6 0V9h6z" clip-rule="evenodd"></path></svg>
                                        <div style="font-size:13px; font-weight:600; color:#0f172a; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $pkg['name'] }}</div>
                                    </div>
                                    <span style="display:inline-flex; align-items:center; gap:8px; flex-shrink:0;">
                                        @if ($pkg['count'] > 1)
                                            <span style="font-size:11px; font-weight:500; color:#94a3b8;">{{ $pkg['count'] }} issues</span>
                                        @endif
                                        <span style="display:inline-flex; align-items:center; font-size:11px; font-weight:500; padding:1px 7px; border-radius:4px; color:{{ $sevColour }}; background:#fff; border:1px solid {{ $sevColour }};">{{ ucfirst(strtolower($pkg['highest'])) }}</span>
                                    </span>
                                </div>
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
                    <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#94a3b8;">Updates Available</div>
                    <button type="button" x-on:click="open = !open" aria-label="Toggle updates list" style="display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:500; padding:3px 10px; border-radius:5px; color:#3b82f6; background:#fff; border:1px solid #3b82f6; cursor:pointer; font-family:inherit;">
                        <span>{{ count($packages) }} {{ count($packages) === 1 ? 'update' : 'updates' }} available</span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" x-bind:style="{ transform: open ? 'rotate(180deg)' : null }" style="display:block; flex-shrink:0; transition:transform 0.15s ease;">
                            <path d="m4 6 4 4 4-4" />
                        </svg>
                    </button>
                </div>
                <div x-show="open" x-cloak style="margin-top:8px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; overflow:hidden;">
                    @foreach($packages as $i => $pkg)
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:6px 12px; {{ $i < count($packages) - 1 ? 'border-bottom:1px solid #e2e8f0;' : '' }}">
                            <div style="display:flex; align-items:center; gap:6px; min-width:0;">
                                @if(!empty($pkg['security_update']))
                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 20 20" fill="#dc2626" style="flex-shrink:0;"><title>Security update available</title><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1zm3 8V5.5a3 3 0 1 0-6 0V9h6z" clip-rule="evenodd"></path></svg>
                                @endif
                                <div style="font-size:13px; font-weight:600; color:#0f172a; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $pkg['name'] }}</div>
                            </div>
                            <span style="display:inline-flex; align-items:center; font-size:11px; font-weight:500; padding:1px 7px; border-radius:4px; color:#3b82f6; background:#fff; border:1px solid #3b82f6; flex-shrink:0; font-variant-numeric:tabular-nums;">{{ $pkg['current'] }} → {{ $pkg['latest'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @elseif(!empty($d['outdated']) && in_array($d['status'], ['ok', 'vulnerable']))
            <div style="border-top:1px solid #e2e8f0; padding-top:12px;">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                    <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#94a3b8;">Updates Available</div>
                    <span style="display:inline-flex; align-items:center; font-size:12px; font-weight:500; padding:3px 10px; border-radius:5px; color:#10b981; background:#fff; border:1px solid #10b981;">All packages up to date</span>
                </div>
            </div>
        @endif

    </div>
    @endforeach

        </div>

        {{-- History tab --}}
        <div x-show="tab === 'history'" role="tabpanel" x-cloak>

            @if (empty($history))

                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:48px 24px; text-align:center;">
                    <p style="font-size:15px; font-weight:600; color:#0f172a; margin:0 0 6px 0;">No update history yet</p>
                    <p style="font-size:13px; color:#64748b; margin:0; line-height:1.55; max-width:420px; margin-left:auto; margin-right:auto;">Entries will appear here as your Statamic, Laravel, PHP or package versions change. The next change will be the first row.</p>
                </div>

            @else

                @php
                    $cols = [
                        'statamic'          => ['label' => 'Statamic', 'short' => null],
                        'laravel'           => ['label' => 'Laravel',  'short' => null],
                        'php'               => ['label' => 'PHP',      'short' => null],
                        'composer_outdated' => ['label' => 'Composer', 'short' => null],
                        'npm_outdated'      => ['label' => 'npm',      'short' => null],
                    ];
                @endphp

                <div style="border:1px solid #e2e8f0; border-radius:8px; overflow:hidden;">
                    <div style="overflow-x:auto;">
                        <table style="width:100%; border-collapse:collapse; font-size:13px;">
                            <thead>
                                <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0;">
                                    <th style="text-align:left; padding:10px 14px; font-weight:600; color:#475569; white-space:nowrap;">Date</th>
                                    @foreach ($cols as $key => $col)
                                        <th style="text-align:left; padding:10px 14px; font-weight:600; color:#475569; white-space:nowrap;">
                                            {{ $col['label'] }}
                                            @if ($col['short'])
                                                <span style="display:block; font-size:11px; font-weight:500; color:#94a3b8;">{{ $col['short'] }}</span>
                                            @endif
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($history as $i => $entry)
                                    @php
                                        $previous = $history[$i + 1] ?? null;

                                        try {
                                            $recordedAt = \Carbon\Carbon::parse($entry['recorded_at'])->format('j M Y, H:i');
                                        } catch (\Throwable $e) {
                                            $recordedAt = $entry['recorded_at'] ?? '—';
                                        }
                                    @endphp
                                    <tr @if (! $loop->last) style="border-bottom:1px solid #f1f5f9;" @endif>
                                        <td style="padding:10px 14px; color:#475569; white-space:nowrap;">{{ $recordedAt }}</td>
                                        @foreach ($cols as $key => $col)
                                            @php
                                                $value   = $entry[$key] ?? null;
                                                $display = $value === null ? '—' : (string) $value;
                                            @endphp
                                            <td style="padding:10px 14px; white-space:nowrap; color:#0f172a;">
                                                {{ $display }}
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <p style="font-size:12px; color:#94a3b8; margin:10px 2px 0 2px;">Newest first. Retained for {{ \D3Creative\Sentinel\Services\HistoryService::RETENTION_DAYS }} days.</p>

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
                    <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#94a3b8;">Email status report</div>
                    <div style="font-size:11px; color:#94a3b8;">Snapshot of current versions, vulnerabilities, and updates</div>
                </div>
                <form action="{{ route('statamic.cp.d3-sentinel.send-report') }}"
                      method="POST"
                      x-on:submit.prevent="send($event.target)"
                      style="display:flex; gap:8px; align-items:center;">
                    @csrf
                    <input type="text"
                           name="email"
                           value="{{ $userEmail }}"
                           required
                           placeholder="email@example.com, another@example.com"
                           style="flex:1; font-size:13px; padding:7px 12px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; color:#1e293b; outline:none; min-width:0;">
                    <button type="button"
                            x-on:click="$dispatch('sentinel-preview-open', { url: '{{ route('statamic.cp.d3-sentinel.preview-report') }}', title: 'Status report preview' })"
                            style="flex-shrink:0; font-size:13px; font-weight:600; color:#0f172a; background:#fff; border:1px solid #e2e8f0; padding:7px 12px; border-radius:6px; cursor:pointer; white-space:nowrap;">
                        Preview
                    </button>
                    <button type="submit"
                            x-bind:disabled="sending"
                            x-bind:style="{ background: state === 'success' ? '#10b981' : (state === 'error' ? '#ef4444' : '#0f172a') }"
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
                     x-bind:style="{ color: state === 'success' ? '#10b981' : '#ef4444' }"
                     style="font-size:13px; margin-top:8px;"
                     x-text="message"></div>
            </div>
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
                    <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#94a3b8;">Email update report</div>
                    <div style="font-size:11px; color:#94a3b8;">Diff vs. previous snapshot</div>
                </div>
                <form x-ref="form"
                      action="{{ route('statamic.cp.d3-sentinel.send-update-report') }}"
                      method="POST"
                      x-on:submit.prevent="send($refs.form)"
                      style="display:flex; gap:8px; align-items:center;">
                    @csrf
                    <input type="text"
                           name="email"
                           value="{{ $userEmail }}"
                           required
                           placeholder="email@example.com, another@example.com"
                           style="flex:1; font-size:13px; padding:7px 12px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; color:#1e293b; outline:none; min-width:0;">
                    <button type="button"
                            x-on:click="$dispatch('sentinel-preview-open', { url: '{{ route('statamic.cp.d3-sentinel.preview-update-report') }}', title: 'Update report preview' })"
                            style="flex-shrink:0; font-size:13px; font-weight:600; color:#0f172a; background:#fff; border:1px solid #e2e8f0; padding:7px 12px; border-radius:6px; cursor:pointer; white-space:nowrap;">
                        Preview
                    </button>
                    <button type="submit"
                            x-bind:disabled="sending"
                            x-bind:style="{ background: state === 'success' ? '#10b981' : (state === 'notice' ? '#f59e0b' : (state === 'error' ? '#ef4444' : '#0f172a')) }"
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
                    <span x-text="message" x-bind:style="{ color: state === 'success' ? '#10b981' : (state === 'notice' ? '#f59e0b' : '#ef4444') }"></span>
                    <button type="button"
                            x-show="canForce && !sending"
                            x-on:click="send($refs.form, true)"
                            style="font-size:12px; font-weight:600; color:#0f172a; background:#fff; border:1px solid #e2e8f0; padding:3px 10px; border-radius:5px; cursor:pointer; font-family:inherit;">
                        Send anyway
                    </button>
                </div>
            </div>
        </div>

    </div>
    {{-- /Tabs --}}

    {{-- Footer --}}
    <div style="display:flex; align-items:center; justify-content:flex-start; margin-top:16px; padding-top:14px;">
        <p style="font-size:12px; color:rgb(63 63 71); margin:0; letter-spacing:-0.01em;">
            Sentinel by <a href="https://d3creative.uk/sentinel" target="_blank" style="color:rgb(63 63 71); text-decoration:underline;">D3 Creative</a>. Security and update alerts for Statamic sites.
        </p>
    </div>

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
                style="width:min(960px,95vw); height:min(820px,90vh); padding:0; border:none; border-radius:10px; overflow:hidden; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
            <div style="display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border-bottom:1px solid #e2e8f0; background:#f8fafc;">
                <strong style="font-size:13px; color:#0f172a;" x-text="title"></strong>
                <button type="button"
                        x-on:click="$refs.dlg.close()"
                        style="font-size:12px; font-weight:600; color:#0f172a; background:#fff; border:1px solid #e2e8f0; padding:4px 10px; border-radius:5px; cursor:pointer; font-family:inherit;">
                    Close
                </button>
            </div>
            <template x-if="src">
                <iframe x-bind:src="src"
                        title="Email preview"
                        style="display:block; width:100%; height:calc(100% - 41px); border:none; background:#fff;"></iframe>
            </template>
        </dialog>
    </div>

@endif

</div>

@endsection
