<?php

namespace D3Creative\Sentinel\Console\Commands;

use Illuminate\Console\Command;
use D3Creative\Sentinel\Services\ContentFreezeService;

class FreezeStartCommand extends Command
{
    protected $signature = 'sentinel:freeze:start
                            {notify_at : When to send the heads-up email (e.g. "2026-05-13 08:00")}
                            {freeze_at : When the banner switches on (e.g. "2026-05-13 09:00")}
                            {--email=* : Recipient email addresses. Repeat the flag or supply a comma-separated string.}';

    protected $description = 'Schedule a Sentinel content freeze. Times are interpreted in SENTINEL_FREEZE_TIMEZONE (or the app timezone).';

    public function handle(ContentFreezeService $service): int
    {
        $notifyAt = (string) $this->argument('notify_at');
        $freezeAt = (string) $this->argument('freeze_at');

        $emails = $this->collectEmails((array) $this->option('email'));

        $result = $service->schedule($notifyAt, $freezeAt, $emails, ContentFreezeService::ACTOR_CLI);

        if (! $result['ok']) {
            $this->error($result['message']);
            return self::FAILURE;
        }

        $freeze = $result['freeze'];

        $this->info('Freeze scheduled.');
        $this->line('  ID:        ' . $freeze['id']);
        $this->line('  Notify at: ' . $service->formatTime($freeze['notify_at']));
        $this->line('  Freeze at: ' . $service->formatTime($freeze['freeze_at']));
        $this->line('  Recipients (' . count($freeze['recipients']) . '): ' . implode(', ', $freeze['recipients']));

        return self::SUCCESS;
    }

    /**
     * Accept --email=a@b.com --email=c@d.com or --email="a@b.com, c@d.com"
     * (or any mix). Whitespace and empties stripped, duplicates removed.
     */
    protected function collectEmails(array $raw): array
    {
        $out = [];

        foreach ($raw as $chunk) {
            foreach (explode(',', (string) $chunk) as $piece) {
                $email = trim($piece);
                if ($email !== '') {
                    $out[] = $email;
                }
            }
        }

        return array_values(array_unique($out));
    }
}
