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
        $this->audit = $audit;
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
