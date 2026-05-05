<?php

namespace D3Creative\Sentinel\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SentinelReport extends Mailable
{
    use Queueable, SerializesModels;

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
        $host = parse_url(config('app.url'), PHP_URL_HOST) ?: 'site';

        return $this->subject('Statamic Package Status Report - ' . $host)
                    ->view('statamic-sentinel::emails.report')
                    ->with([
                        'audit'       => $this->audit,
                        'host'        => $host,
                        'utility_url' => cp_route('utilities.sentinel'),
                    ]);
    }
}
