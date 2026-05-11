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

    $sentinelFreezeActive   = $sentinelFreezeCurrent && ($sentinelFreezeCurrent['status'] ?? null) === \D3Creative\Sentinel\Services\ContentFreezeService::STATUS_ACTIVE;
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
             style="position:fixed; top:0; left:0; right:0; z-index:9998; background:#fef3c7; color:#92400e; border-bottom:1px solid #fcd34d; padding:10px 16px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; font-size:13px; line-height:1.45; box-shadow:0 1px 2px rgba(0,0,0,0.04);">
            <div style="max-width:1200px; margin:0 auto; display:flex; align-items:center; gap:10px;">
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
             style="position:fixed; top:0; left:0; right:0; z-index:9998; background:#d1fae5; color:#065f46; border-bottom:1px solid #6ee7b7; padding:10px 16px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; font-size:13px; line-height:1.45; box-shadow:0 1px 2px rgba(0,0,0,0.04);">
            <div style="max-width:1200px; margin:0 auto; display:flex; align-items:center; gap:10px;">
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
