<?php

namespace D3Creative\Sentinel\Console\Commands;

use Illuminate\Console\Command;
use D3Creative\Sentinel\Services\ContentFreezeService;

class FreezeTickNotificationsCommand extends Command
{
    protected $signature = 'sentinel:freeze:tick-notifications';

    protected $description = 'Internal: send the heads-up email when a scheduled freeze reaches its notify_at time. Run every minute by the scheduler.';

    public function handle(ContentFreezeService $service): int
    {
        $service->tickNotifications();

        return self::SUCCESS;
    }
}
