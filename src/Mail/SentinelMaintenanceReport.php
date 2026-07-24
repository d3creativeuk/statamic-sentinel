<?php

namespace D3Creative\Sentinel\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use D3Creative\Sentinel\Support\ReportHosts;

class SentinelMaintenanceReport extends Mailable
{
    use Queueable, SerializesModels, SentinelFromAddress;

    public array $report;

    public function __construct(array $report)
    {
        $this->report = $report;
    }

    /**
     * Using build() rather than envelope()/content() for compatibility
     * with Laravel 8 through 13.
     */
    public function build(): static
    {
        $hosts = ReportHosts::all();
        $label = implode(', ', $hosts);

        $this->applySentinelFrom();

        return $this->subject($label . ' plan summary')
                    ->view('statamic-sentinel::emails.maintenance-report')
                    ->with([
                        'report'    => $this->report,
                        'host'      => $label,
                        'hosts'     => $hosts,
                        'preheader' => 'Statamic Plan Summary',
                    ]);
    }
}
