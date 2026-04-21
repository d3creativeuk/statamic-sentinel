{{--
    D3 Creative — Sentinel Utility (full report)
    Mirrors the widget data, expanded: full vulnerability list + full outdated list.
--}}

@extends('statamic::layout')

@section('title', 'Sentinel')

@section('content')

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

<div x-data x-init="$nextTick(() => window.scrollTo({ top: 0, behavior: 'instant' }))" style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-size: 14px; color: #1e293b;">

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
            <a href="?d3_refresh=1" style="font-size:12px; color:rgb(63 63 71); text-decoration:none;" title="Refresh audit results">↺ Refresh</a>
        </div>
    </header>

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

    {{-- Email report form --}}
    @php $userEmail = auth()->user()?->email ?? ''; @endphp
    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px; margin-bottom:12px;">
        <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#94a3b8; margin-bottom:10px;">Email this report</div>
        <form id="d3-send-report-utility" action="{{ route('statamic.cp.d3-sentinel.send-report') }}" method="POST" style="display:flex; gap:8px; align-items:center;">
            @csrf
            <input type="email"
                   name="email"
                   value="{{ $userEmail }}"
                   required
                   placeholder="Email address"
                   style="flex:1; font-size:13px; padding:7px 12px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; color:#1e293b; outline:none; min-width:0;">
            <button type="submit"
                    id="d3-send-btn-utility"
                    style="flex-shrink:0; font-size:13px; font-weight:600; color:#fff; background:#0f172a; border:none; padding:7px 14px; border-radius:6px; cursor:pointer; white-space:nowrap;">
                Send Report
            </button>
        </form>
        <div id="d3-send-feedback-utility" style="font-size:13px; margin-top:8px; display:none;"></div>
    </div>

    <script>
    (function () {
        var form = document.getElementById('d3-send-report-utility');
        var btn  = document.getElementById('d3-send-btn-utility');
        var msg  = document.getElementById('d3-send-feedback-utility');

        if (! form) return;

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            btn.disabled      = true;
            btn.textContent   = 'Sending…';
            msg.style.display = 'none';

            fetch(form.action, {
                method:  'POST',
                body:    new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) { return res.json().then(function (j) { return { ok: res.ok, body: j }; }); })
            .then(function (res) {
                btn.textContent      = res.ok ? '✓ Sent' : '✕ Failed';
                btn.style.background = res.ok ? '#10b981' : '#ef4444';
                msg.style.color      = res.ok ? '#10b981' : '#ef4444';
                msg.textContent      = res.body.message;
                msg.style.display    = 'block';

                setTimeout(function () {
                    btn.disabled         = false;
                    btn.textContent      = 'Send Report';
                    btn.style.background = '#0f172a';
                }, 4000);
            })
            .catch(function () {
                btn.textContent      = '✕ Failed';
                btn.style.background = '#ef4444';
                msg.style.color      = '#ef4444';
                msg.textContent      = 'Something went wrong. Please try again.';
                msg.style.display    = 'block';

                setTimeout(function () {
                    btn.disabled         = false;
                    btn.textContent      = 'Send Report';
                    btn.style.background = '#0f172a';
                }, 4000);
            });
        });
    })();
    </script>

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
            <span x-data="{ show: false }" x-on:keydown.escape.window="show = false" style="position:relative; display:inline-flex; align-items:center;">
                <button type="button" x-on:click.stop="show = !show" aria-label="About {{ $row['label'] }}" style="display:inline-flex; align-items:center; justify-content:center; background:transparent; border:0; padding:0; color:#64748b; cursor:pointer; outline:none;">
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
                    <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#94a3b8; margin-bottom:6px;">Security Issues</div>
                    @php
                        $flatVulns = [];
                        foreach (['CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'UNKNOWN'] as $sev) {
                            foreach ($d['severities'][$sev]['vulns'] ?? [] as $v) {
                                $flatVulns[] = $v + ['severity' => $sev];
                            }
                        }
                    @endphp
                    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:6px; overflow:hidden;">
                        @foreach($flatVulns as $i => $vuln)
                            @php $sevColour = $severityColour($vuln['severity']); @endphp
                            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:6px 12px; {{ $i < count($flatVulns) - 1 ? 'border-bottom:1px solid #e2e8f0;' : '' }}">
                                <a href="{{ $vuln['url'] }}" target="_blank" style="font-size:13px; font-weight:600; color:#0f172a; text-decoration:none; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $vuln['package'] }}</a>
                                <span style="display:inline-flex; align-items:center; font-size:11px; font-weight:500; padding:1px 7px; border-radius:4px; color:{{ $sevColour }}; background:#fff; border:1px solid {{ $sevColour }}; flex-shrink:0;">{{ ucfirst(strtolower($vuln['severity'])) }}</span>
                            </div>
                        @endforeach
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
                            <div style="font-size:13px; font-weight:600; color:#0f172a; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $pkg['name'] }}</div>
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

    {{-- Footer --}}
    <div style="display:flex; align-items:center; justify-content:flex-start; margin-top:16px; padding-top:14px;">
        <p style="font-size:12px; color:rgb(63 63 71); margin:0; letter-spacing:-0.01em;">
            Sentinel by <a href="https://d3creative.uk/sentinel" target="_blank" style="color:rgb(63 63 71); text-decoration:underline;">D3 Creative</a>. Daily dependency audits for Statamic sites.
        </p>
    </div>

</div>

@endsection
