<?php

namespace D3Creative\Sentinel\Console\Commands;

use Illuminate\Console\Command;
use D3Creative\Sentinel\Services\AuditService;
use D3Creative\Sentinel\Services\ReportSender;
use D3Creative\Sentinel\Services\ScheduleService;
use D3Creative\Sentinel\Services\SentMailService;

class SendStatusReportCommand extends Command
{
    protected $signature = 'sentinel:send-status-report';

    protected $description = 'Send the Sentinel status report email to the recipients configured in the Schedule tab.';

    public function handle(ScheduleService $schedules, ReportSender $sender, AuditService $audit): int
    {
        $config = $schedules->all()['status_report'] ?? null;

        if (! $config || empty($config['enabled'])) {
            $this->info('Status report schedule is disabled. Skipping.');
            return self::SUCCESS;
        }

        if (empty($config['recipients'])) {
            $this->warn('Status report has no recipients configured. Skipping.');
            return self::SUCCESS;
        }

        // Refresh before sending so the email reflects current state, not
        // whatever happened to be in the cache. As a side-effect this also
        // updates the CP's cached audit + history snapshot, so a scheduled
        // report doubles as a background scan. On failure (transient OSV /
        // Packagist outage), fall through and send with the cached audit so
        // the recipient still gets an email - better stale than silent.
        try {
            $audit->refresh();
            $this->info('Refreshed audit data.');
        } catch (\Throwable $e) {
            $this->warn('Scan failed; sending with last cached audit. ' . $e->getMessage());
        }

        $result = $sender->sendStatus($config['recipients'], SentMailService::TRIGGER_SCHEDULED);

        if ($result['ok']) {
            $this->info($result['message']);
            return self::SUCCESS;
        }

        $this->error($result['message']);

        return $result['kind'] === ReportSender::KIND_MAIL_FAILED ? self::FAILURE : self::SUCCESS;
    }
}
