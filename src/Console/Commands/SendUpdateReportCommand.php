<?php

namespace D3Creative\Sentinel\Console\Commands;

use Illuminate\Console\Command;
use D3Creative\Sentinel\Services\ReportSender;
use D3Creative\Sentinel\Services\ScheduleService;

class SendUpdateReportCommand extends Command
{
    protected $signature = 'sentinel:send-update-report';

    protected $description = 'Send the Sentinel update (diff) report email to the recipients configured in the Schedule tab. Skips silently when there are no changes since the last snapshot.';

    public function handle(ScheduleService $schedules, ReportSender $sender): int
    {
        $config = $schedules->all()['update_report'] ?? null;

        if (! $config || empty($config['enabled'])) {
            $this->info('Update report schedule is disabled. Skipping.');
            return self::SUCCESS;
        }

        if (empty($config['recipients'])) {
            $this->warn('Update report has no recipients configured. Skipping.');
            return self::SUCCESS;
        }

        // Scheduled sends never force-replay — "no changes" means no email.
        $result = $sender->sendUpdate($config['recipients'], false);

        $msg = $result['message'];

        switch ($result['kind']) {
            case ReportSender::KIND_SENT:
                $this->info($msg);
                return self::SUCCESS;

            case ReportSender::KIND_NO_CHANGES:
            case ReportSender::KIND_NO_HISTORY:
                $this->info($msg);
                return self::SUCCESS;

            case ReportSender::KIND_MAIL_FAILED:
                $this->error($msg);
                return self::FAILURE;

            default:
                $this->warn($msg);
                return self::SUCCESS;
        }
    }
}
