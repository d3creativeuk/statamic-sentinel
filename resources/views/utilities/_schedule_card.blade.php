{{--
    Schedule card for a single report (status_report or update_report).
    Lives inside its report's tab so users configure send + schedule together.

    Required vars:
      - $key    string  'status_report' or 'update_report'
      - $config array   schedule slice (enabled, frequency, day_of_week,
                        day_of_month, time, recipients[]) - recipients is an
                        array; this partial joins it for the textarea.
--}}
@php
    $cardConfig = $config;
    $cardConfig['recipients'] = implode(', ', $cardConfig['recipients'] ?? []);
@endphp

<div x-data='{
        form: @json($cardConfig),
        savedEnabled: @json($cardConfig["enabled"]),
        sending: false,
        state: "idle",
        message: "",
        save(formEl) {
            this.sending = true;
            this.state = "idle";
            this.message = "";
            fetch(formEl.action, {
                method: "POST",
                body: new FormData(formEl),
                headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" }
            })
            .then(res => res.json().then(body => ({ ok: res.ok, body })))
            .then(res => {
                this.sending = false;
                this.state = res.ok ? "success" : "error";
                this.message = res.body.message;
                if (res.ok) {
                    this.savedEnabled = this.form.enabled;
                    setTimeout(() => { this.state = "idle"; this.message = ""; }, 4000);
                }
            })
            .catch(() => {
                this.sending = false;
                this.state = "error";
                this.message = "Something went wrong. Please try again.";
            });
        },
        cancel() {
            // Flip the toggle off, wait one tick so the hidden checkbox
            // mirrors the new state, then submit. Server receives
            // enabled=unchecked → false, schedule deactivates.
            this.form.enabled = false;
            this.$nextTick(() => this.save(this.$refs.form));
        }
    }'
    style="margin-top:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px;">

    <div style="display:flex; align-items:baseline; justify-content:space-between; gap:12px; margin-bottom:10px;">
        <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#64748b;">Schedule</div>
        <div style="font-size:12px; color:#64748b;">Send automatically on a recurring cadence</div>
    </div>

    <form x-ref="form"
          action="{{ route('statamic.cp.d3-sentinel.save-schedule') }}"
          method="POST"
          x-on:submit.prevent="save($refs.form)">
        @csrf

        {{-- Pill toggle styled to match Statamic's core blueprint toggle. The visible pill is a real <button> for keyboard support; the actual checked-state lives in a hidden checkbox so the form still POSTs `enabled=1` when on. --}}
        <div style="display:flex; align-items:center; gap:10px;">
            <button type="button"
                    role="switch"
                    x-bind:aria-checked="form.enabled"
                    x-on:click="form.enabled = !form.enabled"
                    x-bind:style="form.enabled
                        ? 'position:relative; width:36px; height:20px; padding:0; border:none; border-radius:10px; cursor:pointer; transition:background 0.15s ease; background:#22c55e; flex-shrink:0;'
                        : 'position:relative; width:36px; height:20px; padding:0; border:none; border-radius:10px; cursor:pointer; transition:background 0.15s ease; background:#cbd5e1; flex-shrink:0;'">
                <span aria-hidden="true"
                      x-bind:style="form.enabled
                        ? 'position:absolute; top:2px; left:18px; width:16px; height:16px; background:#fff; border-radius:8px; transition:left 0.15s ease; box-shadow:0 1px 2px rgba(0,0,0,0.15);'
                        : 'position:absolute; top:2px; left:2px; width:16px; height:16px; background:#fff; border-radius:8px; transition:left 0.15s ease; box-shadow:0 1px 2px rgba(0,0,0,0.15);'"></span>
            </button>
            <input type="checkbox"
                   name="{{ $key }}[enabled]"
                   value="1"
                   x-model="form.enabled"
                   tabindex="-1"
                   aria-hidden="true"
                   style="position:absolute; opacity:0; width:0; height:0; pointer-events:none;">
            <label x-on:click="form.enabled = !form.enabled" style="font-size:13px; font-weight:600; color:#0f172a; cursor:pointer; user-select:none;">
                Enable scheduled sends
            </label>
        </div>

        <div x-show="form.enabled" x-cloak style="margin-top:14px;">
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:10px; align-items:end;">
                <label style="display:block; font-size:12px; color:#475569; font-weight:600;">
                    Frequency
                    <select name="{{ $key }}[frequency]"
                            x-model="form.frequency"
                            style="margin-top:4px; display:block; width:100%; font-size:13px; padding:7px 10px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; color:#1e293b; font-family:inherit;">
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                    </select>
                </label>

                <label x-show="form.frequency === 'weekly'" x-cloak style="display:block; font-size:12px; color:#475569; font-weight:600;">
                    Day of week
                    <select name="{{ $key }}[day_of_week]"
                            x-model.number="form.day_of_week"
                            style="margin-top:4px; display:block; width:100%; font-size:13px; padding:7px 10px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; color:#1e293b; font-family:inherit;">
                        <option value="0">Sunday</option>
                        <option value="1">Monday</option>
                        <option value="2">Tuesday</option>
                        <option value="3">Wednesday</option>
                        <option value="4">Thursday</option>
                        <option value="5">Friday</option>
                        <option value="6">Saturday</option>
                    </select>
                </label>

                <label x-show="form.frequency === 'monthly'" x-cloak style="display:block; font-size:12px; color:#475569; font-weight:600;">
                    Day of month
                    <select name="{{ $key }}[day_of_month]"
                            x-model.number="form.day_of_month"
                            style="margin-top:4px; display:block; width:100%; font-size:13px; padding:7px 10px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; color:#1e293b; font-family:inherit;">
                        @for ($d = 1; $d <= 28; $d++)
                            <option value="{{ $d }}">{{ $d }}</option>
                        @endfor
                    </select>
                </label>

                <label style="display:block; font-size:12px; color:#475569; font-weight:600;">
                    Time
                    <input type="time"
                           name="{{ $key }}[time]"
                           x-model="form.time"
                           required
                           style="margin-top:4px; display:block; width:100%; font-size:13px; padding:7px 10px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; color:#1e293b; font-family:inherit;">
                </label>
            </div>

            {{-- Day-of-month note: lives below the grid so it doesn't squash the row. --}}
            <p x-show="form.frequency === 'monthly'" x-cloak style="margin:6px 2px 0 2px; font-size:12px; color:#64748b;">1st through 28th only - months don't all have a 29th-31st.</p>

            <div style="margin-top:12px;">
                <label style="display:block; font-size:12px; color:#475569; font-weight:600;">Recipients</label>
                <span style="display:block; margin-top:2px; font-size:12px; color:#64748b; font-weight:400;">Comma-separated, max 10 addresses. For example: <code style="font-size:11px; background:#f1f5f9; padding:1px 5px; border-radius:3px; color:#475569;">name@example.com, ops@example.com</code></span>
                <textarea name="{{ $key }}[recipients]"
                          x-model="form.recipients"
                          x-bind:required="form.enabled"
                          rows="2"
                          style="margin-top:6px; display:block; width:100%; font-size:13px; padding:7px 10px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; color:#1e293b; font-family:inherit; resize:vertical;"></textarea>
            </div>
        </div>

        {{-- Off state, no active saved schedule: make it crystal clear nothing is running. --}}
        <div x-show="!form.enabled && !savedEnabled" x-cloak
             style="margin-top:12px; padding:10px 12px; border:1px dashed #e2e8f0; border-radius:6px; background:#fff; font-size:13px; color:#64748b;">
            Scheduled sends are off. Toggle on above to configure a recurring send.
        </div>

        {{-- Off state but a schedule is currently active: warn that the toggle alone hasn't stopped it. --}}
        <div x-show="!form.enabled && savedEnabled" x-cloak
             style="margin-top:12px; padding:10px 12px; border:1px solid #fcd34d; border-radius:6px; background:#fffbeb; font-size:13px; color:#92400e;">
            A schedule is currently active. Toggling off doesn't stop it - click <strong>Cancel schedule</strong> below.
        </div>

        {{-- Action row: visible whenever the toggle is on, OR there's an active saved schedule that may need cancelling. --}}
        <div x-show="form.enabled || savedEnabled" x-cloak style="display:flex; align-items:center; gap:12px; margin-top:14px; flex-wrap:wrap;">
            <button type="submit"
                    x-show="form.enabled"
                    x-cloak
                    x-bind:disabled="sending"
                    x-bind:style="{ background: state === 'success' ? '#047857' : (state === 'error' ? '#ef4444' : '#0f172a') }"
                    style="font-size:13px; font-weight:600; color:#fff; background:#0f172a; border:none; padding:7px 14px; border-radius:6px; cursor:pointer; white-space:nowrap;">
                <span x-show="sending" x-cloak style="display:inline-flex; align-items:center; gap:6px;">
                    <span aria-hidden="true" style="display:inline-block; font-size:14px; line-height:1; transform-origin:center; animation:sentinel-spin 1s linear infinite;">↻</span>
                    Saving…
                </span>
                <span x-show="!sending && state === 'success'" x-cloak>✓ Saved</span>
                <span x-show="!sending && state === 'error'" x-cloak>✕ Failed</span>
                <span x-show="!sending && state === 'idle'">Save schedule</span>
            </button>

            <button type="button"
                    x-show="savedEnabled"
                    x-cloak
                    x-bind:disabled="sending"
                    x-on:click="cancel()"
                    style="font-size:13px; font-weight:600; color:#b91c1c; background:#fff; border:1px solid #fecaca; padding:7px 14px; border-radius:6px; cursor:pointer; white-space:nowrap; font-family:inherit;">
                Cancel schedule
            </button>

            <span x-show="message" x-cloak
                  x-text="message"
                  x-bind:style="{ color: state === 'success' ? '#047857' : '#ef4444' }"
                  style="font-size:13px;"></span>
        </div>
    </form>
</div>
