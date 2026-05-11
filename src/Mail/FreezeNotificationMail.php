<?php

namespace D3Creative\Sentinel\Mail;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use D3Creative\Sentinel\Services\ContentFreezeService;

class FreezeNotificationMail extends Mailable
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

        $freezeAtDisplay = $service->formatTime($this->freeze['freeze_at'] ?? null);
        $tz              = $service->timezone();

        $subjectDate = '-';
        try {
            $subjectDate = Carbon::parse($this->freeze['freeze_at'])
                ->setTimezone($tz)
                ->format('j M Y, H:i');
        } catch (\Throwable $e) {
            // Fall through with placeholder.
        }

        return $this->subject($host . ' update scheduled for ' . $subjectDate)
                    ->view('statamic-sentinel::emails.freeze-notification')
                    ->with([
                        'freeze'          => $this->freeze,
                        'host'            => $host,
                        'preheader'       => 'Statamic update scheduled. Banner will appear at ' . $freezeAtDisplay . '.',
                        'freezeAtDisplay' => $freezeAtDisplay,
                    ]);
    }
}
