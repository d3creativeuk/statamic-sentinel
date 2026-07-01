<?php

namespace D3Creative\Sentinel\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use D3Creative\Sentinel\Support\ReportHosts;

class SentinelUpdateReport extends Mailable
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

        return $this->subject($label . ' updates')
                    ->view('statamic-sentinel::emails.update-report')
                    ->with([
                        'report'    => $this->report,
                        'host'      => $label,
                        'hosts'     => $hosts,
                        'preheader' => 'Statamic Package Update Report',
                    ]);
    }
}
