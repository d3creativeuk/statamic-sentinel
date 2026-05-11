<?php

namespace D3Creative\Sentinel\Console\Commands;

use Illuminate\Console\Command;
use D3Creative\Sentinel\Services\ContentFreezeService;

class FreezeCompleteCommand extends Command
{
    protected $signature = 'sentinel:freeze:complete';

    protected $description = 'Mark the current Sentinel content freeze as complete. Sends the all-clear email and clears the banner.';

    public function handle(ContentFreezeService $service): int
    {
        $result = $service->complete(ContentFreezeService::ACTOR_CLI);

        if (! $result['ok']) {
            $this->error($result['message']);
            return self::FAILURE;
        }

        $freeze = $result['freeze'];

        $this->info('Freeze complete.');
        $this->line('  ID:           ' . $freeze['id']);
        $this->line('  Completed at: ' . $service->formatTime($freeze['completed_at']));
        $this->line('  Recipients (' . count($freeze['recipients']) . '): ' . implode(', ', $freeze['recipients']));

        return self::SUCCESS;
    }
}
