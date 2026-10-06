{{--
    A package's note under its Security Issues row, plus the inline editor
    (super users only). Runs inside the row's x-data (`note`, `draft`,
    `editing`). The update report shows the note next to this package's
    vulnerabilities and any it pulls in.
    Expects: $eco, $package, $noteText, $isChild, $isSuper.
--}}
@php $notePad = $isChild ? 'padding:0 12px 8px 32px;' : 'padding:0 12px 8px 12px;'; @endphp
<div x-show="note && !editing" @if($noteText === '') x-cloak @endif>
    <div style="{{ $notePad }} font-size:12px; color:#475569; white-space:pre-line;" x-text="note">{{ $noteText }}</div>
</div>
@if($isSuper)
    <div x-show="editing" x-cloak>
        <div x-data="{
                saving: false,
                error: '',
                save(text) {
                    this.saving = true;
                    this.error = '';
                    const fd = new FormData();
                    fd.append('_token', @js(csrf_token()));
                    fd.append('ecosystem', @js($eco));
                    fd.append('package', @js($package));
                    fd.append('note', text);
                    fetch(@js(route('statamic.cp.d3-sentinel.save-package-note')), {
                        method: 'POST',
                        body: fd,
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                    })
                    .then(res => res.json().then(body => ({ ok: res.ok, body })))
                    .then(res => {
                        this.saving = false;
                        if (res.ok) {
                            this.note = res.body.note;
                            this.editing = false;
                        } else {
                            this.error = res.body.message;
                        }
                    })
                    .catch(() => {
                        this.saving = false;
                        this.error = 'Something went wrong. Please try again.';
                    });
                }
             }"
             style="{{ $notePad }}">
            <textarea x-model="draft" rows="3" maxlength="1000"
                      aria-label="Note on {{ $package }}"
                      placeholder="e.g. Stuck on this version for now. We'll update once a patch is released."
                      style="width:100%; box-sizing:border-box; font-size:13px; padding:7px 12px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; color:#1e293b; font-family:inherit; resize:vertical;"></textarea>
            <div style="font-size:11px; color:#64748b; margin-top:4px;">Shown in the update report whenever {{ $package }}, or a package it pulls in, is listed under Vulnerabilities.</div>
            <div style="display:flex; align-items:center; gap:8px; margin-top:8px;">
                <button type="button" x-on:click="save(draft)" x-bind:disabled="saving"
                        style="font-size:12px; font-weight:600; color:#fff; background:#0f172a; border:none; padding:5px 12px; border-radius:6px; cursor:pointer; font-family:inherit;">
                    <span x-show="saving" x-cloak>Saving…</span>
                    <span x-show="!saving">Save note</span>
                </button>
                <button type="button" x-on:click="editing = false" x-bind:disabled="saving"
                        style="font-size:12px; font-weight:500; color:#475569; background:#fff; border:1px solid #e2e8f0; padding:5px 12px; border-radius:6px; cursor:pointer; font-family:inherit;">Cancel</button>
                <button type="button" x-show="note" x-on:click="save('')" x-bind:disabled="saving"
                        style="margin-left:auto; font-size:12px; font-weight:500; color:#dc2626; background:none; border:none; padding:0; cursor:pointer; font-family:inherit; text-decoration:underline;">Remove note</button>
            </div>
            <div x-show="error" x-cloak style="font-size:12px; color:#ef4444; margin-top:6px;" x-text="error"></div>
        </div>
    </div>
@endif
