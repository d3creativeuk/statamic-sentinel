{{--
    Content Freeze tab content. Renders one of four state views depending on
    $freeze_current's status, plus a history table at the bottom.

    Required vars:
      - $freeze_service  ContentFreezeService instance (for formatTime + timezone)
      - $freeze_current  array|null  current freeze record, or null if none
      - $freeze_history  array       completed-freeze history (newest first)
--}}

@php
    $statusActive    = \D3Creative\Sentinel\Services\ContentFreezeService::STATUS_ACTIVE;
    $statusScheduled = \D3Creative\Sentinel\Services\ContentFreezeService::STATUS_SCHEDULED;
    $statusNotified  = \D3Creative\Sentinel\Services\ContentFreezeService::STATUS_NOTIFIED;

    $tz       = $freeze_service->timezone();
    $serverTz = config('app.timezone') ?: 'UTC';

    $tzHelper = $tz === $serverTz
        ? 'Times in ' . $tz . '.'
        : 'Times in ' . $tz . '. Server is ' . $serverTz . '.';

    $userEmailDefault = auth()->user()?->email ?? '';

    $currentStatus = $freeze_current['status'] ?? null;
@endphp

@if (! $freeze_current)

    {{-- State: no current freeze. Show the schedule form. --}}
    <div x-data="{
            sending: false,
            state: 'idle',
            message: '',
            submit(form) {
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
                    if (res.ok) {
                        setTimeout(() => { window.location.reload(); }, 600);
                    }
                })
                .catch(() => {
                    this.sending = false;
                    this.state = 'error';
                    this.message = 'Something went wrong. Please try again.';
                });
            }
         }"
         style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px 18px;">

        <div style="display:flex; align-items:baseline; justify-content:space-between; gap:12px; margin-bottom:12px;">
            <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#64748b;">Schedule a content freeze</div>
            <div style="font-size:11px; color:#64748b;">{{ $tzHelper }}</div>
        </div>

        <p style="font-size:13px; color:#475569; margin:0 0 14px 0; line-height:1.55;">
            Coordinate a Statamic update by sending a heads-up email, then showing a CP-wide banner while the work is in progress. Mark it complete to send the all-clear and clear the banner.
        </p>

        <form action="{{ route('statamic.cp.d3-sentinel.freeze.schedule') }}"
              method="POST"
              x-on:submit.prevent="submit($event.target)">
            @csrf

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                <label style="display:flex; flex-direction:column; gap:4px;">
                    <span style="font-size:12px; font-weight:600; color:#0f172a;">Send notification at</span>
                    <input type="datetime-local"
                           name="notify_at"
                           required
                           style="font-size:13px; padding:7px 10px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; color:#1e293b; outline:none; font-family:inherit;">
                </label>
                <label style="display:flex; flex-direction:column; gap:4px;">
                    <span style="font-size:12px; font-weight:600; color:#0f172a;">Freeze starts at</span>
                    <input type="datetime-local"
                           name="freeze_at"
                           required
                           style="font-size:13px; padding:7px 10px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; color:#1e293b; outline:none; font-family:inherit;">
                </label>
            </div>

            <label style="display:flex; flex-direction:column; gap:4px; margin-bottom:14px;">
                <span style="font-size:12px; font-weight:600; color:#0f172a;">Notify (comma-separated emails)</span>
                <input type="text"
                       name="email"
                       value="{{ $userEmailDefault }}"
                       required
                       placeholder="email@example.com, another@example.com"
                       style="font-size:13px; padding:7px 12px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; color:#1e293b; outline:none; font-family:inherit;">
                <span style="font-size:11px; color:#64748b;">Maximum 10 recipients. They receive the heads-up email at the time above, and the all-clear email when the freeze ends.</span>
            </label>

            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <button type="submit"
                        x-bind:disabled="sending"
                        x-bind:style="{ background: state === 'success' ? '#047857' : (state === 'error' ? '#ef4444' : '#0f172a') }"
                        style="font-size:13px; font-weight:600; color:#fff; background:#0f172a; border:none; padding:8px 16px; border-radius:6px; cursor:pointer; font-family:inherit;">
                    <span x-show="sending" x-cloak style="display:inline-flex; align-items:center; gap:6px;">
                        <span aria-hidden="true" style="display:inline-block; font-size:14px; line-height:1; transform-origin:center; animation:sentinel-spin 1s linear infinite;">↻</span>
                        Scheduling…
                    </span>
                    <span x-show="!sending && state === 'success'" x-cloak>✓ Scheduled</span>
                    <span x-show="!sending && state === 'error'" x-cloak>✕ Failed</span>
                    <span x-show="!sending && state === 'idle'">Schedule freeze</span>
                </button>
                <button type="button"
                        x-on:click="$dispatch('sentinel-preview-open', { url: @js(route('statamic.cp.d3-sentinel.preview-freeze-notification')), title: 'Heads-up email preview' })"
                        style="font-size:13px; font-weight:600; color:#0f172a; background:#fff; border:1px solid #e2e8f0; padding:7px 12px; border-radius:6px; cursor:pointer; font-family:inherit;">
                    Preview heads-up email
                </button>
                <button type="button"
                        x-on:click="$dispatch('sentinel-preview-open', { url: @js(route('statamic.cp.d3-sentinel.preview-freeze-completion')), title: 'All-clear email preview' })"
                        style="font-size:13px; font-weight:600; color:#0f172a; background:#fff; border:1px solid #e2e8f0; padding:7px 12px; border-radius:6px; cursor:pointer; font-family:inherit;">
                    Preview all-clear email
                </button>
                <div x-show="message" x-cloak
                     x-bind:style="{ color: state === 'success' ? '#047857' : '#ef4444' }"
                     style="font-size:13px;"
                     x-text="message"></div>
            </div>
        </form>
    </div>

@else

    {{-- State: current freeze exists. Show its status + actions. --}}
    @php
        $notifyAtDisplay   = $freeze_service->formatTime($freeze_current['notify_at'] ?? null);
        $freezeAtDisplay   = $freeze_service->formatTime($freeze_current['freeze_at'] ?? null);
        $notifiedAtDisplay = $freeze_service->formatTime($freeze_current['notified_at'] ?? null);
        $activatedDisplay  = $freeze_service->formatTime($freeze_current['activated_at'] ?? $freeze_current['freeze_at'] ?? null);
        $recipientCount    = count($freeze_current['recipients'] ?? []);

        $stateBg = $currentStatus === $statusActive ? '#fef3c7' : '#dbeafe';
        $stateBorder = $currentStatus === $statusActive ? '#fcd34d' : '#bfdbfe';
        $stateColor = $currentStatus === $statusActive ? '#92400e' : '#1e40af';
        $stateLabel = match ($currentStatus) {
            $statusActive    => 'Active',
            $statusNotified  => 'Notified, waiting for freeze start',
            $statusScheduled => 'Scheduled',
            default          => ucfirst((string) $currentStatus),
        };
    @endphp

    <div style="background:{{ $stateBg }}; border:1px solid {{ $stateBorder }}; border-radius:8px; padding:16px 18px; margin-bottom:14px;">
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
            <span style="display:inline-block; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:{{ $stateColor }}; background:#fff; border:1px solid {{ $stateColor }}; padding:2px 8px; border-radius:4px;">{{ $stateLabel }}</span>
        </div>

        @if ($currentStatus === $statusScheduled)
            <p style="font-size:14px; color:#0f172a; margin:0 0 8px 0; line-height:1.55;">
                Notification will be sent at <strong style="font-variant-numeric:tabular-nums;">{{ $notifyAtDisplay }}</strong>. The freeze banner will appear at <strong style="font-variant-numeric:tabular-nums;">{{ $freezeAtDisplay }}</strong>.
            </p>
            <p style="font-size:13px; color:#475569; margin:0; line-height:1.55;">{{ $recipientCount }} {{ \Illuminate\Support\Str::plural('recipient', $recipientCount) }} on this freeze.</p>
        @elseif ($currentStatus === $statusNotified)
            <p style="font-size:14px; color:#0f172a; margin:0 0 8px 0; line-height:1.55;">
                Heads-up email sent at <strong style="font-variant-numeric:tabular-nums;">{{ $notifiedAtDisplay }}</strong>. The freeze banner switches on at <strong style="font-variant-numeric:tabular-nums;">{{ $freezeAtDisplay }}</strong>.
            </p>
            <p style="font-size:13px; color:#475569; margin:0; line-height:1.55;">Notified {{ $recipientCount }} {{ \Illuminate\Support\Str::plural('recipient', $recipientCount) }}.</p>
        @elseif ($currentStatus === $statusActive)
            <p style="font-size:14px; color:#0f172a; margin:0 0 12px 0; line-height:1.55;">
                Freeze active since <strong style="font-variant-numeric:tabular-nums;">{{ $activatedDisplay }}</strong>. CP users are seeing the banner. Mark complete when the update is finished to send the all-clear email and clear the banner.
            </p>

            <div x-data="{
                    sending: false,
                    state: 'idle',
                    message: '',
                    submit(form) {
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
                            if (res.ok) {
                                setTimeout(() => { window.location.reload(); }, 600);
                            }
                        })
                        .catch(() => {
                            this.sending = false;
                            this.state = 'error';
                            this.message = 'Something went wrong. Please try again.';
                        });
                    }
                 }">
                <form action="{{ route('statamic.cp.d3-sentinel.freeze.complete') }}"
                      method="POST"
                      x-on:submit.prevent="submit($event.target)"
                      style="display:flex; align-items:center; gap:10px;">
                    @csrf
                    <button type="submit"
                            x-bind:disabled="sending"
                            x-bind:style="{ background: state === 'success' ? '#047857' : (state === 'error' ? '#ef4444' : '#0f172a') }"
                            style="font-size:13px; font-weight:600; color:#fff; background:#0f172a; border:none; padding:8px 16px; border-radius:6px; cursor:pointer; font-family:inherit;">
                        <span x-show="sending" x-cloak style="display:inline-flex; align-items:center; gap:6px;">
                            <span aria-hidden="true" style="display:inline-block; font-size:14px; line-height:1; transform-origin:center; animation:sentinel-spin 1s linear infinite;">↻</span>
                            Sending…
                        </span>
                        <span x-show="!sending && state === 'success'" x-cloak>✓ Complete</span>
                        <span x-show="!sending && state === 'error'" x-cloak>✕ Failed</span>
                        <span x-show="!sending && state === 'idle'">Mark as complete</span>
                    </button>
                    <div x-show="message" x-cloak
                         x-bind:style="{ color: state === 'success' ? '#047857' : '#ef4444' }"
                         style="font-size:13px;"
                         x-text="message"></div>
                </form>
            </div>
        @endif

        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-top:14px; padding-top:12px; border-top:1px solid {{ $stateBorder }};">
            <span style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:{{ $stateColor }};">Preview emails</span>
            <button type="button"
                    x-on:click="$dispatch('sentinel-preview-open', { url: @js(route('statamic.cp.d3-sentinel.preview-freeze-notification')), title: 'Heads-up email preview' })"
                    style="font-size:12px; font-weight:600; color:#0f172a; background:#fff; border:1px solid #e2e8f0; padding:5px 10px; border-radius:5px; cursor:pointer; font-family:inherit;">
                Heads-up email
            </button>
            <button type="button"
                    x-on:click="$dispatch('sentinel-preview-open', { url: @js(route('statamic.cp.d3-sentinel.preview-freeze-completion')), title: 'All-clear email preview' })"
                    style="font-size:12px; font-weight:600; color:#0f172a; background:#fff; border:1px solid #e2e8f0; padding:5px 10px; border-radius:5px; cursor:pointer; font-family:inherit;">
                All-clear email
            </button>
        </div>
    </div>

@endif

{{-- History table --}}
@if (! empty($freeze_history))
    <div style="margin-top:20px;">
        <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#64748b; margin-bottom:10px;">Past freezes</div>
        <div style="border:1px solid #e2e8f0; border-radius:8px; overflow:hidden;">
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0;">
                            <th style="text-align:left; padding:10px 14px; font-weight:600; color:#475569; white-space:nowrap;">Notified</th>
                            <th style="text-align:left; padding:10px 14px; font-weight:600; color:#475569; white-space:nowrap;">Started</th>
                            <th style="text-align:left; padding:10px 14px; font-weight:600; color:#475569; white-space:nowrap;">Completed</th>
                            <th style="text-align:right; padding:10px 14px; font-weight:600; color:#475569; white-space:nowrap;">Recipients</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (array_slice($freeze_history, 0, 20) as $entry)
                            <tr style="{{ ! $loop->last ? 'border-bottom:1px solid #f1f5f9;' : '' }}">
                                <td style="padding:10px 14px; white-space:nowrap; color:#0f172a; font-variant-numeric:tabular-nums;">{{ $freeze_service->formatTime($entry['notified_at'] ?? $entry['notify_at'] ?? null) }}</td>
                                <td style="padding:10px 14px; white-space:nowrap; color:#0f172a; font-variant-numeric:tabular-nums;">{{ $freeze_service->formatTime($entry['activated_at'] ?? $entry['freeze_at'] ?? null) }}</td>
                                <td style="padding:10px 14px; white-space:nowrap; color:#0f172a; font-variant-numeric:tabular-nums;">{{ $freeze_service->formatTime($entry['completed_at'] ?? null) }}</td>
                                <td style="padding:10px 14px; text-align:right; white-space:nowrap; color:#475569;">{{ count($entry['recipients'] ?? []) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @if (count($freeze_history) > 20)
            <p style="font-size:12px; color:#64748b; margin:10px 2px 0 2px;">Showing 20 most recent of {{ count($freeze_history) }} total.</p>
        @else
            <p style="font-size:12px; color:#64748b; margin:10px 2px 0 2px;">Newest first. Retained for last {{ \D3Creative\Sentinel\Services\ContentFreezeService::HISTORY_LIMIT }} freezes.</p>
        @endif
    </div>
@endif
