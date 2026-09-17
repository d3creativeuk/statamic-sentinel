<?php

namespace D3Creative\Sentinel\Console\Commands;

use Illuminate\Console\Command;
use D3Creative\Sentinel\Services\AuditService;

class ScanCommand extends Command
{
    protected $signature = 'sentinel:scan';

    protected $description = 'Run a Sentinel audit and cache the result.';

    public function handle(AuditService $audit): int
    {
        $this->info('Running Sentinel audit…');

        $start  = microtime(true);
        $result = $audit->refresh();
        $secs   = round(microtime(true) - $start, 1);

        $composerVulns = $result['composer']['total_vulns'] ?? 0;
        $npmVulns      = $result['npm']['total_vulns']      ?? 0;
        $composerOut   = $result['composer']['outdated']['total'] ?? 0;
        $npmOut        = $result['npm']['outdated']['total']      ?? 0;

        $this->info("Done in {$secs}s.");
        $this->line("  Composer: {$composerVulns} security issue(s), {$composerOut} update(s) available");
        $this->line("  npm:      {$npmVulns} security issue(s), {$npmOut} update(s) available");

        if (! empty($result['license']['supported'])) {
            $licenseLabel = [
                'ok' => 'licensed', 'renewal' => 'renewal due', 'invalid' => 'not licensed',
                'trial' => 'trial', 'free' => 'free edition', 'unknown' => 'could not verify',
            ][$result['license']['status'] ?? 'unknown'] ?? 'could not verify';
            $this->line("  License:  {$licenseLabel}");
        }

        // A failed lookup reports zeros, so say so and exit non-zero for cron
        // monitoring instead of printing "0 security issue(s)" as a success.
        $failed = [];

        foreach (['composer' => 'Composer', 'npm' => 'npm'] as $key => $label) {
            if (($result[$key]['status'] ?? null) === 'error') {
                $failed[] = "{$label} vulnerability check failed: " . ($result[$key]['message'] ?? 'unknown error');
            }

            if (! empty($result[$key]['outdated']['error'])) {
                $failed[] = "{$label} update check failed: the package registry could not be reached";
            }
        }

        foreach ($failed as $message) {
            $this->error("  {$message}");
        }

        return empty($failed) ? self::SUCCESS : self::FAILURE;
    }
}
