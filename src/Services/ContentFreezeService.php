<?php

namespace D3Creative\Sentinel\Services;

use Carbon\Carbon;
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
     */
    public function lastCompleted(): ?array
    {
        $history = $this->history();

        return $history[0] ?? null;
    }

    /**
     * Validate and create a new freeze record. Returns:
     *   ['ok' => true,  'freeze' => array]
     *   ['ok' => false, 'message' => string]
     *
     * Times are accepted in the configured timezone and stored UTC.
     */
    public function schedule(string $notifyAtRaw, string $freezeAtRaw, array $recipients, ?string $scheduledBy = null): array
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
     * Send the heads-up email and move status to `notified`. Mail send
     * failure is logged but never throws - the state machine still advances
     * so the next tick won't re-attempt sending to the same recipients.
     */
    public function markNotified(array $freeze): void
    {
        $current = $this->current();

        if (! $current || ($current['status'] ?? null) !== self::STATUS_SCHEDULED || ($current['id'] ?? null) !== ($freeze['id'] ?? null)) {
            return;
        }

        $current['status']      = self::STATUS_NOTIFIED;
        $current['notified_at'] = Carbon::now()->utc()->toIso8601String();

        $this->writeCurrent($current);

        if (empty($current['recipients'])) {
            Log::warning('Sentinel freeze ' . ($current['id'] ?? '?') . ': no recipients to notify.');
            return;
        }

        try {
            Mail::to($current['recipients'])->send(new FreezeNotificationMail($current));
        } catch (\Throwable $e) {
            Log::warning('Sentinel freeze notification email failed: ' . $e->getMessage());
        }
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
     * history file, and clears the current-freeze file. Returns:
     *   ['ok' => true,  'freeze' => array]
     *   ['ok' => false, 'message' => string]
     */
    public function complete(?string $completedBy = null): array
    {
        $current = $this->current();

        if (! $current) {
            return $this->failure('No active freeze to complete.');
        }

        if (($current['status'] ?? null) !== self::STATUS_ACTIVE) {
            return $this->failure('The current freeze is not active yet.');
        }

        $current['status']       = self::STATUS_COMPLETE;
        $current['completed_at'] = Carbon::now()->utc()->toIso8601String();
        $current['completed_by'] = $completedBy ?: self::ACTOR_CLI;

        $this->appendHistory($current);

        try {
            Storage::disk('local')->delete(self::CURRENT_PATH);
        } catch (\Throwable $e) {
            // Silent fail - the history record is the source of truth from
            // this point forward; a stale current-file is recovered below.
        }

        if (! empty($current['recipients'])) {
            try {
                Mail::to($current['recipients'])->send(new FreezeCompletionMail($current));
            } catch (\Throwable $e) {
                Log::warning('Sentinel freeze completion email failed: ' . $e->getMessage());
            }
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
            return (string) $iso;
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
     * Configured display timezone. Falls back to the Laravel app timezone
     * (and finally UTC) when the env var is unset or invalid.
     */
    public function timezone(): string
    {
        $tz = config('sentinel.freeze.timezone') ?: (config('app.timezone') ?: 'UTC');

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
}
