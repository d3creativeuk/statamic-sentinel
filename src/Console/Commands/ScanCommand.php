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

        return self::SUCCESS;
    }
}
