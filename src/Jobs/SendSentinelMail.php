<?php

namespace D3Creative\Sentinel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use D3Creative\Sentinel\Services\SentMailService;

/**
 * Owns the actual send so the sent-mail log reflects what really happened,
 * not merely that a job was enqueued. Calling Mail::send() (rather than
 * queue()) means the transport runs inside this job: a delivery failure
 * throws here and lands in failed(), while success marks the record sent.
 *
 * On the sync queue this runs inline during the CP request, so the outcome
 * resolves immediately; on a real queue the record stays "queued" until a
 * worker processes the job. Either way the log mirrors the worker outcome.
 */
class SendSentinelMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * One attempt only: a manual "send now" wants a prompt, deterministic
     * result rather than minutes of silent retries. A transient SMTP hiccup
     * therefore records as failed rather than retrying - acceptable trade-off.
     */
    public int $tries = 1;

    public string $recordId;

    /** @var array<int, string> */
    public array $recipients;

    public Mailable $mailable;

    /**
     * @param array<int, string> $recipients
     */
    public function __construct(string $recordId, array $recipients, Mailable $mailable)
    {
        $this->recordId   = $recordId;
        $this->recipients = $recipients;
        $this->mailable   = $mailable;
    }

    public function handle(): void
    {
        Mail::to($this->recipients)->send($this->mailable);

        app(SentMailService::class)->updateOutcome(
            $this->recordId,
            SentMailService::OUTCOME_SENT
        );
    }

    /**
     * Invoked when handle() throws (transport refused / auth failure / the
     * whole envelope rejected). Records the failure with the transport's
     * message so it surfaces in the log exactly like the queue:work FAIL line.
     */
    public function failed(\Throwable $e): void
    {
        app(SentMailService::class)->updateOutcome(
            $this->recordId,
            SentMailService::OUTCOME_FAILED,
            $e->getMessage()
        );
    }
}
