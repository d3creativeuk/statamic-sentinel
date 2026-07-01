<?php

namespace D3Creative\Sentinel\Services;

use Carbon\Carbon;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use D3Creative\Sentinel\Mail\FreezeCompletionMail;
use D3Creative\Sentinel\Mail\FreezeNotificationMail;

/**
 * Coordinated update-window state machine. Persists to two JSON files in
 * storage/app/statamic-sentinel/ - the current freeze (one at a time) and a
 * completed-freeze history. Mirrors ScheduleService / HistoryService for
 * read/write conventions: silent on read failure, atomic .tmp -> rename on
 * write. State transitions are idempotent so the every-minute ticker can
 * fire repeatedly without harm.
 */
class ContentFreezeService
{
    const CURRENT_PATH      = 'statamic-sentinel/content-freeze.json';
    const CURRENT_TMP_PATH  = 'statamic-sentinel/content-freeze.json.tmp';
    const HISTORY_PATH      = 'statamic-sentinel/content-freeze-history.json';
    const HISTORY_TMP_PATH  = 'statamic-sentinel/content-freeze-history.json.tmp';
    const LAST_CANCEL_PATH      = 'statamic-sentinel/content-freeze-last-cancel.json';
    const LAST_CANCEL_TMP_PATH  = 'statamic-sentinel/content-freeze-last-cancel.json.tmp';

    const HISTORY_LIMIT     = 50;
    const SCHEDULE_LEAD_MIN = 5;

    const STATUS_SCHEDULED  = 'scheduled';
    const STATUS_NOTIFIED   = 'notified';
    const STATUS_ACTIVE     = 'active';
    const STATUS_COMPLETE   = 'complete';

    const ACTOR_CLI         = 'cli';

    /**
     * Returns the current non-complete freeze record, or null if none exists.
     * Silent on read failure - never breaks CP rendering.
     */
    public function current(): ?array
    {
        try {
            $disk = Storage::disk('local');

            if (! $disk->exists(self::CURRENT_PATH)) {
                return null;
            }

            $decoded = json_decode($disk->get(self::CURRENT_PATH), true);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Completed-freeze history, newest first. Returns [] on any read failure.
     */
    public function history(): array
    {
        try {
            $disk = Storage::disk('local');

            if (! $disk->exists(self::HISTORY_PATH)) {
                return [];
            }

            $decoded = json_decode($disk->get(self::HISTORY_PATH), true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Most recently completed freeze, used by the CP layout injector to
     * decide whether to render the green dismissible "all clear" banner.
     *
     * Suppressed (returns null) when the latest history entry predates the
     * most recent cancel - otherwise cancelling a freshly-scheduled freeze
     * resurfaces the green banner from a *previous* completed freeze that
     * has nothing to do with the cancel that just happened.
     */
    public function lastCompleted(): ?array
    {
        $history = $this->history();
        $latest  = $history[0] ?? null;

        if (! $latest) {
            return null;
        }

        $cancelAt = $this->lastCancelAt();
        $doneAt   = $latest['completed_at'] ?? null;

        if ($cancelAt && $doneAt) {
            try {
                if (Carbon::parse($doneAt)->lessThan(Carbon::parse($cancelAt))) {
                    return null;
                }
            } catch (\Throwable $e) {
                // Fall through and return the entry if dates can't be compared.
            }
        }

        return $latest;
    }

    /**
     * ISO timestamp of the most recent freeze cancellation, or null if none
     * has been recorded. Used to suppress the green completion banner from
     * older history when the user has just cancelled a different freeze.
     */
    public function lastCancelAt(): ?string
    {
        try {
            $disk = Storage::disk('local');

            if (! $disk->exists(self::LAST_CANCEL_PATH)) {
                return null;
            }

            $decoded = json_decode($disk->get(self::LAST_CANCEL_PATH), true);

            return is_array($decoded) ? ($decoded['cancelled_at'] ?? null) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Validate and create a new freeze record. Returns:
     *   ['ok' => true,  'freeze' => array]
     *   ['ok' => false, 'message' => string]
     *
     * Times are accepted in the configured timezone and stored UTC.
     */
    public function schedule(string $notifyAtRaw, string $freezeAtRaw, array $recipients, ?string $scheduledBy = null, array $options = []): array
    {
        $tz = $this->timezone();

        try {
            $notifyAt = Carbon::parse($notifyAtRaw, $tz);
            $freezeAt = Carbon::parse($freezeAtRaw, $tz);
        } catch (\Throwable $e) {
            return $this->failure('Could not parse the supplied dates. Use a format like 2026-05-13 09:00.');
        }

        $minNotify = Carbon::now($tz)->addMinutes(self::SCHEDULE_LEAD_MIN);

        if ($notifyAt->lessThan($minNotify)) {
            return $this->failure('Notification time must be at least ' . self::SCHEDULE_LEAD_MIN . ' minutes from now.');
        }

        if (! $freezeAt->greaterThan($notifyAt)) {
            return $this->failure('Freeze start must be after the notification time.');
        }

        // Optional freeze end + expected duration. Both are informational - used
        // by the notification email only; they do not auto-end the freeze.
        $endsAt    = null;
        $endsAtRaw = trim((string) ($options['freeze_ends_at'] ?? ''));

        if ($endsAtRaw !== '') {
            try {
                $endsAt = Carbon::parse($endsAtRaw, $tz);
            } catch (\Throwable $e) {
                return $this->failure('Could not parse the freeze end time. Use a format like 2026-05-13 12:00.');
            }

            if (! $endsAt->greaterThan($freezeAt)) {
                return $this->failure('Freeze end must be after the freeze start time.');
            }
        }

        $expectedDurationMinutes = null;
        $expectedRaw             = $options['expected_duration'] ?? null;

        if ($expectedRaw !== null && trim((string) $expectedRaw) !== '') {
            $unit        = strtolower(trim((string) ($options['expected_duration_unit'] ?? 'minutes'))) ?: 'minutes';
            $multipliers = ['minutes' => 1, 'hours' => 60, 'days' => 1440];
            $number      = filter_var($expectedRaw, FILTER_VALIDATE_INT);

            if ($number === false || $number <= 0 || ! isset($multipliers[$unit])) {
                return $this->failure('Expected duration must be a positive number of minutes, hours, or days.');
            }

            $expectedDurationMinutes = $number * $multipliers[$unit];
        }

        $recipients = array_values(array_filter(array_map('trim', $recipients)));

        if (empty($recipients)) {
            return $this->failure('Add at least one recipient email.');
        }

        if (count($recipients) > 10) {
            return $this->failure('Too many recipients (max 10).');
        }

        foreach ($recipients as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->failure('Invalid email address: ' . $email);
            }
        }

        if ($this->current() !== null) {
            return $this->failure('A freeze is already scheduled or in progress. Mark it complete first.');
        }

        $freeze = [
            'id'             => 'freeze_' . Str::lower(Str::random(16)),
            'notify_at'      => $notifyAt->copy()->utc()->toIso8601String(),
            'freeze_at'      => $freezeAt->copy()->utc()->toIso8601String(),
            'freeze_ends_at' => $endsAt ? $endsAt->copy()->utc()->toIso8601String() : null,
            'expected_duration_minutes' => $expectedDurationMinutes,
            'notified_at'    => null,
            'activated_at'   => null,
            'completed_at'   => null,
            'status'         => self::STATUS_SCHEDULED,
            'scheduled_by'   => $scheduledBy ?: self::ACTOR_CLI,
            'completed_by'   => null,
            'recipients'     => $recipients,
            'created_at'     => Carbon::now()->utc()->toIso8601String(),
        ];

        if (! $this->writeCurrent($freeze)) {
            return $this->failure('Failed to save the freeze record. Check storage permissions.');
        }

        return ['ok' => true, 'freeze' => $freeze];
    }

    /**
     * Idempotently advance the state machine through both transitions
     * (scheduled -> notified, notified -> active) in a single call. Safe to
     * invoke from request flow because each underlying tick is cheap when
     * nothing's due and the mail send is wrapped in try/catch.
     *
     * Called from InjectFreezeBanner so any CP page load catches up the
     * state machine in environments where `schedule:run` cron isn't wired
     * up (dev / Herd). In production, cron normally beats this to the
     * transition - which is fine, both call paths are idempotent.
     */
    public function tickIfDue(): void
    {
        try {
            // Cache lock so the every-minute cron and the per-request middleware
            // can't both pass the status guard and double-dispatch the heads-up
            // email when they happen to fire in the same second. `get()` with a
            // closure auto-releases after the callback (or on exception).
            // Drivers that don't support locks (array) silently no-op the lock
            // - acceptable for dev/test, real production should run a driver
            // that does (file is fine for single-host; redis for multi-host).
            Cache::lock('sentinel_freeze_tick', 30)->get(function () {
                $this->tickNotifications();
                $this->tickActivations();
            });
        } catch (\Throwable $e) {
            // Silent fail - never break CP rendering on a tick failure.
        }
    }

    /**
     * Find scheduled freezes whose notify_at has passed and dispatch the
     * heads-up email. Idempotent: refuses to advance a freeze that's no
     * longer in `scheduled` status.
     */
    public function tickNotifications(): int
    {
        $freeze = $this->current();

        if (! $freeze || ($freeze['status'] ?? null) !== self::STATUS_SCHEDULED) {
            return 0;
        }

        try {
            $notifyAt = Carbon::parse($freeze['notify_at']);
        } catch (\Throwable $e) {
            return 0;
        }

        if (Carbon::now()->lessThan($notifyAt)) {
            return 0;
        }

        $this->markNotified($freeze);

        return 1;
    }

    /**
     * Find notified freezes whose freeze_at has passed and switch on the
     * banner. Idempotent: refuses to advance a freeze that's not in
     * `notified` status.
     */
    public function tickActivations(): int
    {
        $freeze = $this->current();

        if (! $freeze || ($freeze['status'] ?? null) !== self::STATUS_NOTIFIED) {
            return 0;
        }

        try {
            $freezeAt = Carbon::parse($freeze['freeze_at']);
        } catch (\Throwable $e) {
            return 0;
        }

        if (Carbon::now()->lessThan($freezeAt)) {
            return 0;
        }

        $this->activate($freeze);

        return 1;
    }

    /**
     * Send the heads-up email and move status to `notified`. Mail is dispatched
     * BEFORE the state write so a dispatch failure (SMTP down on the sync
     * driver, queue config wrong, malformed template) leaves the freeze at
     * `scheduled` and the next tick retries. With no valid recipients we
     * advance anyway so the state machine doesn't stall on a permanently-bad
     * record.
     */
    public function markNotified(array $freeze): void
    {
        $current = $this->current();

        if (! $current || ($current['status'] ?? null) !== self::STATUS_SCHEDULED || ($current['id'] ?? null) !== ($freeze['id'] ?? null)) {
            return;
        }

        $recipients = $this->filterValidRecipients($current['recipients'] ?? [], $current['id'] ?? '?');

        if (! empty($recipients)) {
            try {
                Mail::to($recipients)->queue(new FreezeNotificationMail($current));
            } catch (\Throwable $e) {
                Log::warning('Sentinel freeze notification mail dispatch failed: ' . $e->getMessage());
                return;
            }
        } else {
            Log::warning('Sentinel freeze ' . ($current['id'] ?? '?') . ': no valid recipients to notify; advancing anyway.');
        }

        $current['status']      = self::STATUS_NOTIFIED;
        $current['notified_at'] = Carbon::now()->utc()->toIso8601String();

        $this->writeCurrent($current);
    }

    /**
     * Switch the banner on. No email sent at this transition.
     */
    public function activate(array $freeze): void
    {
        $current = $this->current();

        if (! $current || ($current['status'] ?? null) !== self::STATUS_NOTIFIED || ($current['id'] ?? null) !== ($freeze['id'] ?? null)) {
            return;
        }

        $current['status']       = self::STATUS_ACTIVE;
        $current['activated_at'] = Carbon::now()->utc()->toIso8601String();

        $this->writeCurrent($current);
    }

    /**
     * End the freeze. Sends the all-clear email, moves the record into the
     * history file, and clears the current-freeze file.
     *
     * Allowed from any non-complete state - the user may want to fast-track
     * out of `scheduled` or `notified` without waiting for the freeze to
     * activate (e.g. the update finished faster than expected). To skip the
     * all-clear email, use cancel() instead.
     *
     * Returns:
     *   ['ok' => true,  'freeze' => array]
     *   ['ok' => false, 'message' => string]
     */
    public function complete(?string $completedBy = null): array
    {
        $current = $this->current();

        if (! $current) {
            return $this->failure('No freeze to complete.');
        }

        $current['status']       = self::STATUS_COMPLETE;
        $current['completed_at'] = Carbon::now()->utc()->toIso8601String();
        $current['completed_by'] = $completedBy ?: self::ACTOR_CLI;

        $recipients = $this->filterValidRecipients($current['recipients'] ?? [], $current['id'] ?? '?');

        // Dispatch the all-clear email BEFORE advancing state. complete() is
        // user-initiated (button or CLI command) so a dispatch failure should
        // surface as a retryable failure rather than silently advancing the
        // freeze and losing the email.
        if (! empty($recipients)) {
            try {
                Mail::to($recipients)->queue(new FreezeCompletionMail($current));
            } catch (\Throwable $e) {
                Log::warning('Sentinel freeze completion mail dispatch failed: ' . $e->getMessage());
                return $this->failure('Could not send the all-clear email. Check your mail configuration and try again.');
            }
        }

        $this->appendHistory($current);

        try {
            Storage::disk('local')->delete(self::CURRENT_PATH);
        } catch (\Throwable $e) {
            // Silent fail - the history record is the source of truth from
            // this point forward.
        }

        return ['ok' => true, 'freeze' => $current];
    }

    /**
     * Abort a freeze before it activates. Allowed while status is `scheduled`
     * or `notified`; an `active` freeze must be marked complete instead so
     * recipients still get the all-clear email. Deletes the current-freeze
     * file without appending to history - cancellations are not part of the
     * audit trail today.
     *
     * Returns:
     *   ['ok' => true,  'freeze' => array, 'was_notified' => bool]
     *   ['ok' => false, 'message' => string]
     */
    public function cancel(?string $cancelledBy = null): array
    {
        $current = $this->current();

        if (! $current) {
            return $this->failure('No scheduled freeze to cancel.');
        }

        $status = $current['status'] ?? null;

        if ($status === self::STATUS_ACTIVE) {
            return $this->failure('The freeze is already active. Mark it complete to send the all-clear email.');
        }

        if ($status !== self::STATUS_SCHEDULED && $status !== self::STATUS_NOTIFIED) {
            return $this->failure('This freeze can no longer be cancelled.');
        }

        try {
            Storage::disk('local')->delete(self::CURRENT_PATH);
        } catch (\Throwable $e) {
            return $this->failure('Failed to remove the freeze record. Check storage permissions.');
        }

        // Record the cancel timestamp so older completed freezes in history
        // don't keep the green "Update complete" banner alive after a cancel.
        // .tmp + move so a crash mid-write doesn't leave partial JSON that
        // would make lastCancelAt() return null and resurface the banner.
        // Silent on failure - the banner suppression is best-effort.
        try {
            $disk = Storage::disk('local');
            $json = json_encode([
                'cancelled_at' => Carbon::now()->utc()->toIso8601String(),
                'cancelled_by' => $cancelledBy,
                'freeze_id'    => $current['id'] ?? null,
            ], JSON_UNESCAPED_SLASHES);

            $disk->put(self::LAST_CANCEL_TMP_PATH, $json);

            if (! $disk->move(self::LAST_CANCEL_TMP_PATH, self::LAST_CANCEL_PATH)) {
                $disk->delete(self::LAST_CANCEL_PATH);
                $disk->move(self::LAST_CANCEL_TMP_PATH, self::LAST_CANCEL_PATH);
            }
        } catch (\Throwable $e) {
            // Silent fail.
        }

        return [
            'ok'           => true,
            'freeze'       => $current,
            'was_notified' => $status === self::STATUS_NOTIFIED,
        ];
    }

    /**
     * Format a UTC timestamp for display. When the configured display tz
     * differs from the server tz, returns dual times: "08:00 GMT / 04:00 EDT".
     */
    public function formatTime($iso): string
    {
        if (! $iso) {
            return '-';
        }

        try {
            $time = Carbon::parse($iso);
        } catch (\Throwable $e) {
            // Garbage in the stored ISO (corruption, manual edit) renders as
            // a placeholder rather than printing the raw string into emails
            // and the CP modal.
            return '-';
        }

        $displayTz = $this->timezone();
        $serverTz  = config('app.timezone') ?: 'UTC';

        $primary = $time->copy()->setTimezone($displayTz)->format('j M Y, H:i T');

        if ($displayTz === $serverTz) {
            return $primary;
        }

        $secondary = $time->copy()->setTimezone($serverTz)->format('H:i T');

        return $primary . ' / ' . $secondary;
    }

    /**
     * Human-friendly duration for a minute count: 30 -> "30 minutes",
     * 180 -> "3 hours", 90 -> "1 hour 30 minutes". Empty string for a
     * non-positive count or on any formatting error - callers treat that
     * as "no duration to show".
     */
    public function formatDuration(int $minutes): string
    {
        if ($minutes <= 0) {
            return '';
        }

        try {
            return CarbonInterval::minutes($minutes)->cascade()->forHumans();
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Derived strings the heads-up email needs from a freeze record: the
     * formatted end time, the maintenance window (freeze_at -> freeze_ends_at),
     * and the expected duration. Each is null when the source data is absent,
     * so legacy freeze records (written before these fields existed) render
     * the email exactly as before. Shared by the mailable and the preview so
     * the two never drift.
     *
     * @return array{ends_display: ?string, window_text: ?string, expected_text: ?string}
     */
    public function notificationExtras(array $freeze): array
    {
        $startIso = $freeze['freeze_at'] ?? null;
        $endsIso  = $freeze['freeze_ends_at'] ?? null;
        $expected = $freeze['expected_duration_minutes'] ?? null;

        $windowText = null;
        if ($startIso && $endsIso) {
            try {
                $mins       = Carbon::parse($startIso)->diffInMinutes(Carbon::parse($endsIso));
                $windowText = $this->formatDuration((int) $mins) ?: null;
            } catch (\Throwable $e) {
                $windowText = null;
            }
        }

        $expectedText = null;
        if (is_numeric($expected) && (int) $expected > 0) {
            $expectedText = $this->formatDuration((int) $expected) ?: null;
        }

        return [
            'ends_display'  => $endsIso ? $this->formatTime($endsIso) : null,
            'window_text'   => $windowText,
            'expected_text' => $expectedText,
        ];
    }

    /**
     * Best-effort freeze record built from raw schedule-form input, used by the
     * heads-up email preview so it reflects what the user has typed rather than
     * a placeholder. Unlike schedule() this neither validates nor persists:
     * blank or unparseable values fall back to null (or now for the start/notify
     * times) so the preview always renders. Datetime-local strings are parsed in
     * the display timezone; expected duration is normalised to minutes.
     *
     * @param array<string, mixed> $input
     */
    public function draftRecord(array $input): array
    {
        $tz  = $this->timezone();
        $now = Carbon::now()->toIso8601String();

        $expectedMinutes = null;
        $number          = filter_var($input['expected_duration'] ?? null, FILTER_VALIDATE_INT);
        $unit            = strtolower(trim((string) ($input['expected_duration_unit'] ?? 'minutes'))) ?: 'minutes';
        $multipliers     = ['minutes' => 1, 'hours' => 60, 'days' => 1440];

        if ($number !== false && $number > 0 && isset($multipliers[$unit])) {
            $expectedMinutes = $number * $multipliers[$unit];
        }

        return [
            'notify_at'                 => $this->toUtcIso((string) ($input['notify_at'] ?? ''), $tz) ?? $now,
            'freeze_at'                 => $this->toUtcIso((string) ($input['freeze_at'] ?? ''), $tz) ?? $now,
            'freeze_ends_at'            => $this->toUtcIso((string) ($input['freeze_ends_at'] ?? ''), $tz),
            'expected_duration_minutes' => $expectedMinutes,
            'completed_at'              => $now,
            'recipients'                => [],
        ];
    }

    /**
     * Parse a raw datetime string in the given timezone and return it as a UTC
     * ISO-8601 string. Null for an empty or unparseable value - callers treat
     * that as "not supplied".
     */
    protected function toUtcIso(string $raw, string $tz): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw, $tz)->utc()->toIso8601String();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Configured display timezone. Falls back to the Laravel app timezone
     * (and finally UTC) when the env var is unset or invalid.
     */
    public function timezone(): string
    {
        $tz = config('statamic-sentinel.freeze.timezone') ?: (config('app.timezone') ?: 'UTC');

        try {
            new \DateTimeZone($tz);
        } catch (\Throwable $e) {
            return 'UTC';
        }

        return $tz;
    }

    protected function writeCurrent(array $freeze): bool
    {
        try {
            $disk = Storage::disk('local');
            $json = json_encode($freeze, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            $disk->put(self::CURRENT_TMP_PATH, $json);

            if (! $disk->move(self::CURRENT_TMP_PATH, self::CURRENT_PATH)) {
                $disk->delete(self::CURRENT_PATH);
                $disk->move(self::CURRENT_TMP_PATH, self::CURRENT_PATH);
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Remove a single freeze-history entry by `id`. Returns true if a row was
     * removed, false if the id matched nothing or the write failed. Mirrors
     * HistoryService::delete - atomic filter-and-rewrite of the JSON file.
     */
    public function deleteHistory(string $id): bool
    {
        try {
            $entries = $this->history();
            $before  = count($entries);

            $entries = array_values(array_filter(
                $entries,
                fn ($e) => ($e['id'] ?? null) !== $id
            ));

            if (count($entries) === $before) {
                return false;
            }

            $disk = Storage::disk('local');
            $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            $disk->put(self::HISTORY_TMP_PATH, $json);

            if (! $disk->move(self::HISTORY_TMP_PATH, self::HISTORY_PATH)) {
                $disk->delete(self::HISTORY_PATH);
                $disk->move(self::HISTORY_TMP_PATH, self::HISTORY_PATH);
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function appendHistory(array $freeze): void
    {
        try {
            $entries = $this->history();

            array_unshift($entries, $freeze);

            if (count($entries) > self::HISTORY_LIMIT) {
                $entries = array_slice($entries, 0, self::HISTORY_LIMIT);
            }

            $disk = Storage::disk('local');
            $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            $disk->put(self::HISTORY_TMP_PATH, $json);

            if (! $disk->move(self::HISTORY_TMP_PATH, self::HISTORY_PATH)) {
                $disk->delete(self::HISTORY_PATH);
                $disk->move(self::HISTORY_TMP_PATH, self::HISTORY_PATH);
            }
        } catch (\Throwable $e) {
            // Silent fail - history is bookkeeping, not part of the contract.
        }
    }

    protected function failure(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }

    /**
     * Filter a stored recipient list through FILTER_VALIDATE_EMAIL just before
     * dispatch. The current-freeze JSON file can sit on disk for hours/days
     * after schedule() validated the input; corruption or manual edits could
     * leave invalid addresses in place, and `Mail::to([bad])` throws at
     * dispatch. Invalid entries are dropped with a log warning rather than
     * killing the whole send.
     */
    protected function filterValidRecipients(array $recipients, string $freezeId): array
    {
        $valid = [];
        foreach ($recipients as $email) {
            if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $valid[] = $email;
            } else {
                Log::warning('Sentinel freeze ' . $freezeId . ': dropping invalid recipient ' . var_export($email, true));
            }
        }

        return $valid;
    }
}
