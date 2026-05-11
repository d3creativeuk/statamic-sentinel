<?php

namespace D3Creative\Sentinel\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use D3Creative\Sentinel\Services\ContentFreezeService;

class FreezeCompletionMail extends Mailable
{
    use Queueable, SerializesModels;

    public array $freeze;

    public function __construct(array $freeze)
    {
        $this->freeze = $freeze;
    }

    /**
     * Using build() rather than envelope()/content() for compatibility
     * with Laravel 8 through 13.
     */
    public function build(): static
    {
        $host    = parse_url(config('app.url'), PHP_URL_HOST) ?: 'site';
        $service = app(ContentFreezeService::class);

        return $this->subject($host . ' update complete: safe to log back in')
                    ->view('statamic-sentinel::emails.freeze-completion')
                    ->with([
                        'freeze'             => $this->freeze,
                        'host'               => $host,
                        'preheader'          => 'Statamic update complete. The control panel is safe to use again.',
                        'completedAtDisplay' => $service->formatTime($this->freeze['completed_at'] ?? null),
                    ]);
    }
}
