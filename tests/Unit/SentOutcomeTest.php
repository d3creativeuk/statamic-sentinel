<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Jobs\SendSentinelMail;
use D3Creative\Sentinel\Mail\SentinelReport;
use D3Creative\Sentinel\Services\AuditService;
use D3Creative\Sentinel\Services\ReportSender;
use D3Creative\Sentinel\Services\SentMailService;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Regression coverage for the fix where the sent-mail log recorded "Sent" the
 * instant a mail was enqueued - so a job that later FAILed in the worker still
 * showed as delivered. The outcome is now owned by SendSentinelMail: a record
 * starts "queued" and the job flips it to "sent" or "failed" once the transport
 * has actually run.
 */
class SentOutcomeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolate the sent-mail index + HTML snapshots from any real storage.
        Storage::fake('local');
    }

    public function test_update_outcome_flips_an_existing_record(): void
    {
        $svc = new SentMailService;
        $id  = $svc->record(SentMailService::KIND_STATUS, ['a@b.com'], SentMailService::TRIGGER_MANUAL, SentMailService::OUTCOME_QUEUED, '<p>hi</p>');

        $this->assertNotNull($id);
        $this->assertTrue($svc->updateOutcome($id, SentMailService::OUTCOME_SENT));

        // Fresh instance reads from disk, bypassing the per-instance memo.
        $this->assertSame('sent', (new SentMailService)->find($id)['outcome']);
    }

    public function test_update_outcome_records_the_error_on_failure(): void
    {
        $svc = new SentMailService;
        $id  = $svc->record(SentMailService::KIND_UPDATE, ['a@b.com'], SentMailService::TRIGGER_MANUAL, SentMailService::OUTCOME_QUEUED, '');

        $this->assertTrue($svc->updateOutcome($id, SentMailService::OUTCOME_FAILED, 'smtp refused'));

        $entry = (new SentMailService)->find($id);
        $this->assertSame('failed', $entry['outcome']);
        $this->assertSame('smtp refused', $entry['error']);
    }

    public function test_update_outcome_rejects_unknown_and_malformed_ids(): void
    {
        $svc = new SentMailService;
        $svc->record(SentMailService::KIND_STATUS, ['a@b.com'], SentMailService::TRIGGER_MANUAL, SentMailService::OUTCOME_QUEUED, '');

        // Well-formed id (16 chars) but not on record.
        $this->assertFalse($svc->updateOutcome('AbCdEfGhIjKlMnOp', SentMailService::OUTCOME_SENT));
        // Malformed id - rejected before touching the index.
        $this->assertFalse($svc->updateOutcome('nope', SentMailService::OUTCOME_SENT));
    }

    public function test_job_marks_the_record_sent_when_the_transport_accepts(): void
    {
        Mail::fake();

        $svc = new SentMailService;
        $id  = $svc->record(SentMailService::KIND_STATUS, ['x@y.com'], SentMailService::TRIGGER_MANUAL, SentMailService::OUTCOME_QUEUED, '');

        (new SendSentinelMail($id, ['x@y.com'], new SentinelReport([])))->handle();

        Mail::assertSent(SentinelReport::class);
        $this->assertSame('sent', (new SentMailService)->find($id)['outcome']);
    }

    public function test_job_failed_hook_marks_the_record_failed(): void
    {
        // Mirrors the worker's FAIL line: handle() threw, failed() runs.
        $svc = new SentMailService;
        $id  = $svc->record(SentMailService::KIND_UPDATE, ['x@y.com'], SentMailService::TRIGGER_MANUAL, SentMailService::OUTCOME_QUEUED, '');

        (new SendSentinelMail($id, ['x@y.com'], new SentinelReport([])))
            ->failed(new \RuntimeException('Connection could not be established'));

        $entry = (new SentMailService)->find($id);
        $this->assertSame('failed', $entry['outcome']);
        $this->assertSame('Connection could not be established', $entry['error']);
    }

    public function test_send_status_resolves_to_sent_on_the_sync_queue(): void
    {
        config(['queue.default' => 'sync']);
        Mail::fake();
        Cache::forever(AuditService::CACHE_KEY, []); // run() returns cheaply, no network

        $result = (new ReportSender)->sendStatus(['a@b.com']);

        $this->assertSame(ReportSender::KIND_SENT, $result['kind']);
        $this->assertTrue($result['ok']);

        $rows = (new SentMailService)->forKind(SentMailService::KIND_STATUS);
        $this->assertSame('sent', $rows[0]['outcome']);
    }

    public function test_send_status_stays_queued_on_an_async_queue(): void
    {
        Queue::fake(); // job is pushed but never processed
        Cache::forever(AuditService::CACHE_KEY, []);

        $result = (new ReportSender)->sendStatus(['a@b.com']);

        Queue::assertPushed(SendSentinelMail::class);
        $this->assertSame(ReportSender::KIND_QUEUED, $result['kind']);
        $this->assertTrue($result['ok']);

        $rows = (new SentMailService)->forKind(SentMailService::KIND_STATUS);
        $this->assertSame('queued', $rows[0]['outcome']);
    }
}
