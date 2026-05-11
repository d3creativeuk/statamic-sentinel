<?php

namespace D3Creative\Sentinel\Console\Commands;

use Illuminate\Console\Command;
use D3Creative\Sentinel\Services\ContentFreezeService;

class FreezeTickActivationsCommand extends Command
{
    protected $signature = 'sentinel:freeze:tick-activations';

    protected $description = 'Internal: switch the freeze banner on when the freeze_at time arrives. Run every minute by the scheduler.';

    public function handle(ContentFreezeService $service): int
    {
        $service->tickActivations();

        return self::SUCCESS;
    }
}
