{{--
    Sentinel Content Freeze - CP banner / modal markup.

    Rendered by InjectFreezeBanner middleware on every CP HTML response and
    inserted just before </body>. Returns an empty string when there is no
    active or recently-completed freeze (or when the user isn't authenticated)
    so the middleware can skip injection cleanly.
--}}

@php
    try {
        $sentinelFreezeService = app(\D3Creative\Sentinel\Services\ContentFreezeService::class);
        $sentinelFreezeCurrent = $sentinelFreezeService->current();
        $sentinelFreezeRecent  = $sentinelFreezeCurrent ? null : $sentinelFreezeService->lastCompleted();
    } catch (\Throwable $e) {
        $sentinelFreezeCurrent = null;
        $sentinelFreezeRecent  = null;
    }

    $sentinelFreezeStatus     = $sentinelFreezeCurrent['status'] ?? null;
    $sentinelFreezeActive     = $sentinelFreezeCurrent && $sentinelFreezeStatus === \D3Creative\Sentinel\Services\ContentFreezeService::STATUS_ACTIVE;
    $sentinelFreezeUpcoming   = $sentinelFreezeCurrent && in_array($sentinelFreezeStatus, [
        \D3Creative\Sentinel\Services\ContentFreezeService::STATUS_SCHEDULED,
        \D3Creative\Sentinel\Services\ContentFreezeService::STATUS_NOTIFIED,
    ], true);
    $sentinelFreezeComplete = $sentinelFreezeRecent !== null;
@endphp

@if ($sentinelFreezeActive)
    @php
        $sentinelActiveId  = $sentinelFreezeCurrent['id'] ?? 'unknown';
        $sentinelStartedAt = $sentinelFreezeService->formatTime($sentinelFreezeCurrent['activated_at'] ?? $sentinelFreezeCurrent['freeze_at'] ?? null);
    @endphp

    {{-- Active freeze: non-dismissible amber banner + first-load modal --}}
    <div x-data="{
            visible: true,
            modalOpen: false,
            cookieName: 'sentinel_freeze_modal_seen_{{ $sentinelActiveId }}',
            init() {
                if (! document.cookie.split('; ').some(c => c.startsWith(this.cookieName + '='))) {
                    this.$nextTick(() => {
                        this.modalOpen = true;
                        if (this.$refs.dlg && typeof this.$refs.dlg.showModal === 'function') {
                            try { this.$refs.dlg.showModal(); } catch (e) {}
                        }
                    });
                }
            },
            dismissModal() {
                document.cookie = this.cookieName + '=1; max-age=2592000; path=/; SameSite=Lax';
                this.modalOpen = false;
                if (this.$refs.dlg && this.$refs.dlg.open) {
                    try { this.$refs.dlg.close(); } catch (e) {}
                }
            }
         }"
         x-cloak>

        <div x-show="visible"
             role="status"
             aria-live="polite"
             style="background:#fef3c7; color:#92400e; border-bottom:1px solid #fcd34d; padding:10px 16px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; font-size:13px; line-height:1.45;">
            <div style="display:flex; align-items:center; gap:10px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;" aria-hidden="true">
                    <path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                </svg>
                <span><strong>Statamic update in progress.</strong> Please don't edit content until you receive the all-clear email.</span>
            </div>
        </div>

        <dialog x-ref="dlg"
                x-on:click.self="dismissModal()"
                x-on:close="dismissModal()"
                style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); width:min(440px,90vw); margin:0; padding:0; border:none; border-radius:10px; overflow:hidden; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
            <div style="padding:24px 28px;">
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                    <span style="display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:50%; background:#fef3c7; color:#92400e; flex-shrink:0;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                        </svg>
                    </span>
                    <h2 style="font-size:16px; font-weight:600; color:#0f172a; margin:0;">Statamic update in progress</h2>
                </div>
                <p style="font-size:14px; color:#475569; margin:0 0 14px 0; line-height:1.55;">
                    An update to this site is currently in progress, started at <strong style="color:#0f172a; font-variant-numeric:tabular-nums;">{{ $sentinelStartedAt }}</strong>. Please don't edit content until you receive an email confirming the update is complete.
                </p>
            </div>
            <div style="display:flex; align-items:center; justify-content:flex-end; padding:12px 28px; background:#f8fafc; border-top:1px solid #e2e8f0;">
                <button type="button"
                        x-on:click="dismissModal()"
                        style="font-size:13px; font-weight:600; color:#fff; background:#0f172a; border:none; padding:8px 18px; border-radius:6px; cursor:pointer; font-family:inherit;">
                    Got it
                </button>
            </div>
        </dialog>
    </div>
@elseif ($sentinelFreezeUpcoming)
    @php
        $sentinelUpcomingId        = $sentinelFreezeCurrent['id'] ?? 'unknown';
        $sentinelFreezeAt          = $sentinelFreezeService->formatTime($sentinelFreezeCurrent['freeze_at'] ?? null);
        $sentinelHost              = parse_url(config('app.url'), PHP_URL_HOST) ?: 'site';
        $sentinelUpcomingDismissed = session()->has('sentinel_freeze_dismissed_' . $sentinelUpcomingId);
    @endphp

    {{-- Upcoming freeze (scheduled / notified): blue dismissible banner.
         Dismissal is stored in the Laravel session, which is regenerated on
         login - so the banner reappears for each user's next sign-in. --}}
    @if (! $sentinelUpcomingDismissed)
        <div x-data="{
                visible: true,
                modalOpen: false,
                openModal() {
                    this.modalOpen = true;
                    if (this.$refs.dlg && typeof this.$refs.dlg.showModal === 'function') {
                        try { this.$refs.dlg.showModal(); } catch (e) {}
                    }
                },
                closeModal() {
                    this.modalOpen = false;
                    if (this.$refs.dlg && this.$refs.dlg.open) {
                        try { this.$refs.dlg.close(); } catch (e) {}
                    }
                },
                dismiss() {
                    this.visible = false;
                    const fd = new FormData();
                    fd.append('id', @js($sentinelUpcomingId));
                    fd.append('_token', @js(csrf_token()));
                    fetch(@js(route('statamic.cp.d3-sentinel.freeze.dismiss-banner')), {
                        method: 'POST',
                        body: fd,
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                    }).catch(() => {});
                }
             }"
             x-cloak>
            <div x-show="visible"
                 role="status"
                 aria-live="polite"
                 style="background:#dbeafe; color:#1e40af; border-bottom:1px solid #bfdbfe; padding:10px 16px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; font-size:13px; line-height:1.45;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;" aria-hidden="true">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                    <span style="flex:1;"><strong>A Statamic update is scheduled for {{ $sentinelHost }}.</strong> Update window starts <span style="font-variant-numeric:tabular-nums;">{{ $sentinelFreezeAt }}</span>.</span>
                    <button type="button"
                            x-on:click="openModal()"
                            style="flex-shrink:0; background:transparent; border:none; color:#1e40af; cursor:pointer; padding:2px 6px; font-size:13px; font-weight:600; text-decoration:underline; font-family:inherit;">
                        Learn more
                    </button>
                    <button type="button"
                            x-on:click="dismiss()"
                            aria-label="Dismiss"
                            style="flex-shrink:0; background:transparent; border:none; color:#1e40af; cursor:pointer; padding:2px 6px; font-size:18px; line-height:1; font-family:inherit;">&times;</button>
                </div>
            </div>

            <dialog x-ref="dlg"
                    x-on:click.self="closeModal()"
                    x-on:close="closeModal()"
                    style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); width:min(540px,92vw); margin:0; padding:0; border:none; border-radius:10px; overflow:hidden; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
                <div style="background:#0f172a; padding:20px 28px;">
                    <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#94a3b8; margin-bottom:6px;">Heads up</div>
                    <h2 style="font-size:18px; font-weight:600; color:#ffffff; margin:0; line-height:1.3;">A Statamic update is scheduled for {{ $sentinelHost }}</h2>
                </div>

                <div style="padding:22px 28px;">
                    <p style="font-size:14px; color:#1e293b; margin:0 0 16px 0; line-height:1.55;">
                        We're going to apply an update to <strong>{{ $sentinelHost }}</strong> shortly. While the update is in progress, the Statamic control panel will display a banner asking everyone to hold off on editing content.
                    </p>

                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px; margin:0 0 16px 0;">
                        <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#64748b; margin-bottom:4px;">Update window starts</div>
                        <div style="font-size:15px; font-weight:600; color:#0f172a; font-variant-numeric:tabular-nums;">{{ $sentinelFreezeAt }}</div>
                    </div>

                    <p style="font-size:14px; color:#1e293b; margin:0 0 8px 0; line-height:1.55;">
                        <strong>What you need to do:</strong>
                    </p>

                    <ul style="font-size:14px; color:#1e293b; margin:0 0 16px 0; padding-left:20px; line-height:1.6;">
                        <li style="margin-bottom:4px;">Wrap up any in-flight content edits before the start time above.</li>
                        <li style="margin-bottom:4px;">Once the banner appears, please pause editing content in the control panel.</li>
                        <li>You'll get an "all clear" email and banner once the update is complete and it's safe to resume.</li>
                    </ul>

                    <p style="font-size:13px; color:#475569; margin:0; line-height:1.55;">
                        The website itself stays online for visitors throughout. This only affects content editing in the control panel.
                    </p>
                </div>

                <div style="display:flex; align-items:center; justify-content:flex-end; padding:12px 28px; background:#f8fafc; border-top:1px solid #e2e8f0;">
                    <button type="button"
                            x-on:click="closeModal()"
                            style="font-size:13px; font-weight:600; color:#fff; background:#0f172a; border:none; padding:8px 18px; border-radius:6px; cursor:pointer; font-family:inherit;">
                        Got it
                    </button>
                </div>
            </dialog>
        </div>
    @endif
@elseif ($sentinelFreezeComplete)
    @php
        $sentinelRecentId    = $sentinelFreezeRecent['id'] ?? 'unknown';
        $sentinelCompletedAt = $sentinelFreezeService->formatTime($sentinelFreezeRecent['completed_at'] ?? null);
    @endphp

    {{-- Recently completed freeze: green dismissible banner --}}
    <div x-data="{
            visible: false,
            cookieName: 'sentinel_freeze_dismissed_{{ $sentinelRecentId }}',
            init() {
                if (! document.cookie.split('; ').some(c => c.startsWith(this.cookieName + '='))) {
                    this.visible = true;
                }
            },
            dismiss() {
                document.cookie = this.cookieName + '=1; max-age=2592000; path=/; SameSite=Lax';
                this.visible = false;
            }
         }"
         x-cloak>
        <div x-show="visible"
             role="status"
             aria-live="polite"
             style="background:#d1fae5; color:#065f46; border-bottom:1px solid #6ee7b7; padding:10px 16px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; font-size:13px; line-height:1.45;">
            <div style="display:flex; align-items:center; gap:10px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;" aria-hidden="true">
                    <path d="M20 6 9 17l-5-5"/>
                </svg>
                <span style="flex:1;"><strong>Statamic update complete.</strong> You can resume editing. (Completed {{ $sentinelCompletedAt }}.)</span>
                <button type="button"
                        x-on:click="dismiss()"
                        aria-label="Dismiss"
                        style="flex-shrink:0; background:transparent; border:none; color:#065f46; cursor:pointer; padding:2px 6px; font-size:18px; line-height:1; font-family:inherit;">&times;</button>
            </div>
        </div>
    </div>
@endif
