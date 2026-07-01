<?php

namespace D3Creative\Sentinel\Mail;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use D3Creative\Sentinel\Services\ContentFreezeService;
use D3Creative\Sentinel\Support\ReportHosts;

class FreezeNotificationMail extends Mailable
{
    use Queueable, SerializesModels, SentinelFromAddress;

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
        $host    = ReportHosts::label();
        $service = app(ContentFreezeService::class);

        $freezeAtDisplay = $service->formatTime($this->freeze['freeze_at'] ?? null);
        $extras          = $service->notificationExtras($this->freeze);
        $tz              = $service->timezone();

        $subjectDate = '-';
        try {
            $subjectDate = Carbon::parse($this->freeze['freeze_at'])
                ->setTimezone($tz)
                ->format('j M Y, H:i');
        } catch (\Throwable $e) {
            // Fall through with placeholder.
        }

        $this->applySentinelFrom();

        return $this->subject($host . ' update scheduled for ' . $subjectDate)
                    ->view('statamic-sentinel::emails.freeze-notification')
                    ->with([
                        'freeze'              => $this->freeze,
                        'host'                => $host,
                        'preheader'           => 'Statamic update scheduled. Banner will appear at ' . $freezeAtDisplay . '.',
                        'freezeAtDisplay'     => $freezeAtDisplay,
                        'freezeEndsAtDisplay' => $extras['ends_display'],
                        'windowText'          => $extras['window_text'],
                        'expectedText'        => $extras['expected_text'],
                    ]);
    }
}
