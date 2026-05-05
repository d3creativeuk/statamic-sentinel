<?php

namespace D3Creative\Sentinel\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SentinelUpdateReport extends Mailable
{
    use Queueable, SerializesModels;

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
        $host = parse_url(config('app.url'), PHP_URL_HOST) ?: 'site';

        return $this->subject($host . ' updates')
                    ->view('statamic-sentinel::emails.update-report')
                    ->with([
                        'report'      => $this->report,
                        'host'        => $host,
                        'utility_url' => cp_route('utilities.sentinel'),
                        'preheader'   => 'Statamic Package Update Report',
                    ]);
    }
}
