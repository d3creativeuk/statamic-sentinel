<?php

namespace D3Creative\Sentinel\Services;

use Illuminate\Support\Facades\Mail;
use D3Creative\Sentinel\Jobs\SendSentinelMail;
use D3Creative\Sentinel\Mail\SentinelReport;
use D3Creative\Sentinel\Mail\SentinelUpdateReport;

/**
 * Shared send logic used by both the HTTP controller and the scheduled
 * artisan commands. Trusts the recipient list it receives - input parsing
 * and address validation belong to the caller (HTTP layer).
 *
 * The actual transport work is handed to SendSentinelMail (a queued job) so
 * the sent-mail log reflects the real outcome: the record starts life as
 * "queued" and the job flips it to "sent" or "failed" once the transport has
 * actually run. On the sync queue that happens inline, so this class re-reads
 * the record after dispatch and reports the resolved outcome to the caller.
 */
class ReportSender
{
    /**
     * Result kinds returned to the caller. Callers use these to map to the
     * right HTTP status / CLI exit code without string-matching messages.
     */
    const KIND_SENT          = 'sent';
    const KIND_QUEUED        = 'queued';
    const KIND_NO_RECIPIENTS = 'no_recipients';
    const KIND_NO_HISTORY    = 'no_history';
    const KIND_NO_CHANGES    = 'no_changes';
    const KIND_MAIL_FAILED   = 'mail_failed';

    public function sendStatus(array $recipients, string $trigger = SentMailService::TRIGGER_MANUAL): array
    {
        if (empty($recipients)) {
            return $this->result(self::KIND_NO_RECIPIENTS, 'No recipients configured.');
        }

        try {
            $audit    = (new AuditService())->run();
            $mailable = new SentinelReport($audit);
            $html     = $this->safeRender($mailable);
        } catch (\Throwable $e) {
            $this->record(SentMailService::KIND_STATUS, $recipients, $trigger, SentMailService::OUTCOME_FAILED, '', $e->getMessage());

            return $this->result(self::KIND_MAIL_FAILED, 'Failed to send report. Please check your mail configuration.');
        }

        $id = $this->record(SentMailService::KIND_STATUS, $recipients, $trigger, SentMailService::OUTCOME_QUEUED, $html);

        return $this->dispatchAndReconcile($id, $recipients, $mailable, [
            'sent'   => 'Report sent successfully.',
            'queued' => 'Report queued for delivery.',
            'failed' => 'Failed to send report. Please check your mail configuration.',
        ]);
    }

    public function sendUpdate(array $recipients, bool $force = false, string $trigger = SentMailService::TRIGGER_MANUAL): array
    {
        if (empty($recipients)) {
            return $this->result(self::KIND_NO_RECIPIENTS, 'No recipients configured.');
        }

        // Use the cached audit (cheap). recordIfChanged() runs inside refresh(),
        // not run(), so capturing a post-update snapshot is the explicit job of
        // the Refresh button. run() falls through to refresh() automatically
        // when the cache is empty (e.g. fresh install).
        (new AuditService())->run();

        $store   = app(HistoryService::class);
        $history = $store->all();

        if (count($history) < 2) {
            return $this->result(
                self::KIND_NO_HISTORY,
                'No earlier snapshot to compare against yet. Apply your updates, then try again.'
            );
        }

        $report = UpdateReportBuilder::build($history[0], $history[1]);

        if (! $report['has_changes']) {
            if (! $force) {
                return $this->result(
                    self::KIND_NO_CHANGES,
                    'No changes detected since the previous snapshot.',
                    ['can_force' => true]
                );
            }

            // Forced resend: replay the last stored non-empty report so the
            // recipient sees the previous meaningful update rather than a wall
            // of "No change" rows. Falls back to the empty current diff if
            // nothing has been remembered yet.
            $report  = $store->lastReport() ?? $report;
            $trigger = SentMailService::TRIGGER_FORCED;
        }

        $mailable = new SentinelUpdateReport($report);
        $html     = $this->safeRender($mailable);

        // Remember the report only when it carries real content, so a future
        // force-resend has something meaningful to replay. Independent of the
        // transport outcome - it's about the report we built, not delivery.
        if ($report['has_changes']) {
            $store->rememberLastReport($report);
        }

        $id = $this->record(SentMailService::KIND_UPDATE, $recipients, $trigger, SentMailService::OUTCOME_QUEUED, $html);

        return $this->dispatchAndReconcile($id, $recipients, $mailable, [
            'sent'   => 'Update report sent successfully.',
            'queued' => 'Update report queued for delivery.',
            'failed' => 'Failed to send update report. Please check your mail configuration.',
        ]);
    }

    /**
     * Hand the send to the queued job, then report the outcome the record
     * actually holds. On the sync queue the job has already run inline by the
     * time dispatch() returns, so the row is "sent"/"failed"; on a real queue
     * it's still "queued" and a worker will resolve it later.
     *
     * @param array{sent: string, queued: string, failed: string} $messages
     */
    protected function dispatchAndReconcile(?string $id, array $recipients, $mailable, array $messages): array
    {
        try {
            SendSentinelMail::dispatch((string) $id, $recipients, $mailable);
        } catch (\Throwable $e) {
            // The queue backend itself was unreachable - nothing was handed off.
            if ($id !== null) {
                $this->updateOutcome($id, SentMailService::OUTCOME_FAILED, $e->getMessage());
            }

            return $this->result(self::KIND_MAIL_FAILED, $messages['failed']);
        }

        $outcome = $this->currentOutcome($id);

        return match ($outcome) {
            SentMailService::OUTCOME_SENT   => $this->result(self::KIND_SENT, $messages['sent']),
            SentMailService::OUTCOME_FAILED => $this->result(self::KIND_MAIL_FAILED, $messages['failed']),
            default                         => $this->result(self::KIND_QUEUED, $messages['queued']),
        };
    }

    /**
     * Read the record's current outcome from a fresh service instance (so the
     * inline sync-queue update is visible). Defaults to "queued" when there's
     * no id or the row can't be read.
     */
    protected function currentOutcome(?string $id): string
    {
        if ($id === null) {
            return SentMailService::OUTCOME_QUEUED;
        }

        try {
            $entry = app(SentMailService::class)->find($id);

            return $entry['outcome'] ?? SentMailService::OUTCOME_QUEUED;
        } catch (\Throwable $e) {
            return SentMailService::OUTCOME_QUEUED;
        }
    }

    /**
     * Render the mailable to HTML for the sent-mail snapshot. Renders before
     * send so the preview matches what was queued, even on transient failures.
     * Render errors must not block the send - fall back to an empty string.
     */
    protected function safeRender($mailable): string
    {
        try {
            return $mailable->render();
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected function record(string $kind, array $recipients, string $trigger, string $outcome, string $html, ?string $error = null): ?string
    {
        try {
            return app(SentMailService::class)->record($kind, $recipients, $trigger, $outcome, $html, $error);
        } catch (\Throwable $e) {
            // Silent fail - recording is bookkeeping, not part of the send contract.
            return null;
        }
    }

    protected function updateOutcome(string $id, string $outcome, ?string $error = null): void
    {
        try {
            app(SentMailService::class)->updateOutcome($id, $outcome, $error);
        } catch (\Throwable $e) {
            // Silent fail - bookkeeping only.
        }
    }

    protected function result(string $kind, string $message, array $extra = []): array
    {
        return array_merge([
            'kind'      => $kind,
            'ok'        => $kind === self::KIND_SENT || $kind === self::KIND_QUEUED,
            'message'   => $message,
            'can_force' => false,
        ], $extra);
    }
}
