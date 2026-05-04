<?php

namespace D3Creative\Sentinel\Services;

use Illuminate\Support\Facades\Mail;
use D3Creative\Sentinel\Mail\SentinelReport;
use D3Creative\Sentinel\Mail\SentinelUpdateReport;

/**
 * Shared send logic used by both the HTTP controller and the scheduled
 * artisan commands. Trusts the recipient list it receives — input parsing
 * and address validation belong to the caller (HTTP layer).
 */
class ReportSender
{
    /**
     * Result kinds returned to the caller. Callers use these to map to the
     * right HTTP status / CLI exit code without string-matching messages.
     */
    const KIND_SENT          = 'sent';
    const KIND_NO_RECIPIENTS = 'no_recipients';
    const KIND_NO_HISTORY    = 'no_history';
    const KIND_NO_CHANGES    = 'no_changes';
    const KIND_MAIL_FAILED   = 'mail_failed';

    public function sendStatus(array $recipients): array
    {
        if (empty($recipients)) {
            return $this->result(self::KIND_NO_RECIPIENTS, 'No recipients configured.');
        }

        try {
            $audit = (new AuditService())->run();

            Mail::to($recipients)->send(new SentinelReport($audit));

            return $this->result(self::KIND_SENT, 'Report sent successfully.');
        } catch (\Throwable $e) {
            return $this->result(self::KIND_MAIL_FAILED, 'Failed to send report. Please check your mail configuration.');
        }
    }

    public function sendUpdate(array $recipients, bool $force = false): array
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
            $report = $store->lastReport() ?? $report;
        }

        try {
            Mail::to($recipients)->send(new SentinelUpdateReport($report));

            // Remember the report only when it carries real content, so a
            // future force-resend has something meaningful to replay.
            if ($report['has_changes']) {
                $store->rememberLastReport($report);
            }

            return $this->result(self::KIND_SENT, 'Update report sent successfully.');
        } catch (\Throwable $e) {
            return $this->result(self::KIND_MAIL_FAILED, 'Failed to send update report. Please check your mail configuration.');
        }
    }

    protected function result(string $kind, string $message, array $extra = []): array
    {
        return array_merge([
            'kind'      => $kind,
            'ok'        => $kind === self::KIND_SENT,
            'message'   => $message,
            'can_force' => false,
        ], $extra);
    }
}
