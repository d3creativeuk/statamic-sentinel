<?php

namespace D3Creative\Sentinel\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use D3Creative\Sentinel\Support\ReportHosts;

class SentinelReport extends Mailable
{
    use Queueable, SerializesModels, SentinelFromAddress;

    public array $audit;

    public function __construct(array $audit)
    {
        $this->audit = self::slim($audit);
    }

    /**
     * Only what emails/report.blade.php reads. The mailable is queued, so the
     * whole audit (every advisory, installed package and outdated list) went
     * into the job payload for an email that shows only totals, past
     * beanstalkd's 64 KB job limit on a site with many advisories.
     */
    public static function slim(array $audit): array
    {
        $ecosystem = function ($eco) {
            if (! is_array($eco)) {
                return $eco;
            }

            $outdated = is_array($eco['outdated'] ?? null) ? $eco['outdated'] : [];

            return array_intersect_key($eco, array_flip(['status', 'message', 'lock_unreadable', 'total_vulns', 'total_packages', 'counts']))
                + ['outdated' => array_intersect_key($outdated, array_flip(['total', 'security_updates_total', 'vendor_security_updates_total', 'error']))];
        };

        foreach (['composer', 'npm'] as $key) {
            if (array_key_exists($key, $audit)) {
                $audit[$key] = $ecosystem($audit[$key]);
            }
        }

        return $audit;
    }

    /**
     * Using build() rather than envelope()/content() for compatibility
     * with Laravel 10 through 13.
     */
    public function build(): static
    {
        $hosts = ReportHosts::all();
        $label = implode(', ', $hosts);

        $this->applySentinelFrom();

        return $this->subject($label . ' status')
                    ->view('statamic-sentinel::emails.report')
                    ->with([
                        'audit'     => $this->audit,
                        'host'      => $label,
                        'hosts'     => $hosts,
                        'preheader' => 'Statamic Package Status Report',
                    ]);
    }
}
