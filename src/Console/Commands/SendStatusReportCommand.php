<?php

namespace D3Creative\Sentinel\Console\Commands;

use Illuminate\Console\Command;
use D3Creative\Sentinel\Services\ReportSender;
use D3Creative\Sentinel\Services\ScheduleService;

class SendStatusReportCommand extends Command
{
    protected $signature = 'sentinel:send-status-report';

    protected $description = 'Send the Sentinel status report email to the recipients configured in the Schedule tab.';

    public function handle(ScheduleService $schedules, ReportSender $sender): int
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

        $result = $sender->sendStatus($config['recipients']);

        if ($result['ok']) {
            $this->info($result['message']);
            return self::SUCCESS;
        }

        $this->error($result['message']);

        return $result['kind'] === ReportSender::KIND_MAIL_FAILED ? self::FAILURE : self::SUCCESS;
    }
}
