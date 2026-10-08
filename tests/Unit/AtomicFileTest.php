<?php

namespace D3Creative\Sentinel\Tests\Unit;

use Carbon\Carbon;
use D3Creative\Sentinel\Jobs\SendSentinelMail;
use D3Creative\Sentinel\Mail\FreezeCompletionMail;
use D3Creative\Sentinel\Mail\FreezeNotificationMail;
use D3Creative\Sentinel\Services\ContentFreezeService;
use D3Creative\Sentinel\Services\PackageNoteService;
use D3Creative\Sentinel\Services\ScheduleService;
use D3Creative\Sentinel\Services\SentMailService;
use D3Creative\Sentinel\Support\AtomicFile;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * A store write must never leave the target worse off than before it. On a
 * full disk Laravel's local disk returns false from put() (leaving a partial
 * temp) instead of throwing, and json_encode() returns false on invalid
 * UTF-8; both used to truncate or delete the store being replaced.
 *
 * @see AtomicFile
 */
class AtomicFileTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/sentinel-atomic-' . uniqid();
        File::ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_replaces_an_existing_file(): void
    {
        $this->useDisk();

        AtomicFile::put('statamic-sentinel/a.json', '{"v":1}');
        AtomicFile::put('statamic-sentinel/a.json', '{"v":2}');

        $this->assertSame('{"v":2}', Storage::disk('local')->get('statamic-sentinel/a.json'));
        $this->assertSame(['statamic-sentinel/a.json'], Storage::disk('local')->allFiles());
    }

    public function test_replaces_an_existing_file_on_a_disk_without_local_paths(): void
    {
        $this->useDisk(localPaths: false);

        AtomicFile::put('statamic-sentinel/a.json', '{"v":1}');
        AtomicFile::put('statamic-sentinel/a.json', '{"v":2}');

        $this->assertSame('{"v":2}', Storage::disk('local')->get('statamic-sentinel/a.json'));
        $this->assertSame(['statamic-sentinel/a.json'], Storage::disk('local')->allFiles());
    }

    public function test_a_failed_temp_write_leaves_the_target_alone(): void
    {
        $this->useDisk();
        AtomicFile::put('statamic-sentinel/a.json', '{"v":1}');

        $this->useDisk(putFails: 'nothing');
        $this->assertWriteThrows('statamic-sentinel/a.json', '{"v":2}');

        $this->assertSame('{"v":1}', Storage::disk('local')->get('statamic-sentinel/a.json'));
        $this->assertSame(['statamic-sentinel/a.json'], Storage::disk('local')->allFiles());
    }

    /**
     * The full-disk case: part of the temp is written, then put() reports
     * false. That partial temp used to be renamed over the store.
     */
    public function test_a_partial_temp_write_is_not_renamed_over_the_target(): void
    {
        $this->useDisk();
        AtomicFile::put('statamic-sentinel/a.json', '{"v":1}');

        $this->useDisk(putFails: 'partial');
        $this->assertWriteThrows('statamic-sentinel/a.json', '{"v":2,"long":"' . str_repeat('x', 100) . '"}');

        $this->assertSame('{"v":1}', Storage::disk('local')->get('statamic-sentinel/a.json'));
        $this->assertSame(['statamic-sentinel/a.json'], Storage::disk('local')->allFiles());
    }

    public function test_refuses_to_write_empty_contents(): void
    {
        $this->useDisk();
        AtomicFile::put('statamic-sentinel/a.json', '{"v":1}');

        $this->assertWriteThrows('statamic-sentinel/a.json', '');

        $this->assertSame('{"v":1}', Storage::disk('local')->get('statamic-sentinel/a.json'));
    }

    public function test_put_json_substitutes_invalid_utf8_instead_of_writing_nothing(): void
    {
        $this->useDisk();

        AtomicFile::putJson('statamic-sentinel/a.json', ['error' => "550 R\xE9cipient"]);

        $this->assertSame(['error' => "550 R\u{FFFD}cipient"], json_decode(Storage::disk('local')->get('statamic-sentinel/a.json'), true));
    }

    public function test_a_store_reports_a_failed_save_and_keeps_its_previous_contents(): void
    {
        $this->useDisk();

        $schedules = new ScheduleService;
        $config    = $schedules->defaults();
        $config['status_report']['time'] = '17:30';
        $this->assertTrue($schedules->save($config));

        $this->useDisk(putFails: 'partial');
        $config['status_report']['time'] = '09:00';
        $this->assertFalse($schedules->save($config));

        $this->assertSame('17:30', $schedules->all()['status_report']['time']);
    }

    /**
     * A transport error carrying a Latin-1 SMTP reply used to empty the whole
     * sent-mail index.
     */
    public function test_a_non_utf8_send_error_keeps_the_sent_log(): void
    {
        $this->useDisk();

        $sent = new SentMailService;
        $kept = $sent->record(SentMailService::KIND_STATUS, ['a@example.com'], 'manual', SentMailService::OUTCOME_SENT);
        $id   = $sent->record(SentMailService::KIND_STATUS, ['b@example.com'], 'manual', SentMailService::OUTCOME_QUEUED);

        (new SendSentinelMail($id, ['b@example.com'], new FreezeCompletionMail([])))
            ->failed(new \RuntimeException("550 Bo\xEEte aux lettres inconnue"));

        $fresh = new SentMailService;
        $this->assertNotNull($fresh->find($kept));
        $this->assertSame(SentMailService::OUTCOME_FAILED, $fresh->find($id)['outcome']);
        $this->assertStringContainsString("Bo\u{FFFD}te", $fresh->find($id)['error']);
    }

    public function test_a_note_with_invalid_utf8_keeps_the_other_notes(): void
    {
        $this->useDisk();

        $notes = new PackageNoteService;
        $this->assertTrue($notes->set('npm', 'braces', 'Waiting on Tailwind 4.', 'u1'));
        $notes->set('npm', 'postcss', "Bad \xE9 byte", 'u1');

        $this->assertSame('Waiting on Tailwind 4.', $notes->all()['npm']['braces']['note']);
    }

    public function test_a_freeze_advances_after_its_heads_up_so_it_is_sent_once(): void
    {
        $this->useDisk();
        Mail::fake();

        Storage::disk('local')->put(ContentFreezeService::CURRENT_PATH, json_encode([
            'id'         => 'abc123',
            'status'     => ContentFreezeService::STATUS_SCHEDULED,
            'notify_at'  => Carbon::now()->subMinute()->toIso8601String(),
            'freeze_at'  => Carbon::now()->addHour()->toIso8601String(),
            'recipients' => ['editor@example.com'],
        ]));

        $freezes = new ContentFreezeService;

        $this->assertSame(1, $freezes->tickNotifications());
        $this->assertSame(0, $freezes->tickNotifications());

        $this->assertSame(ContentFreezeService::STATUS_NOTIFIED, $freezes->current()['status']);
        Mail::assertQueued(FreezeNotificationMail::class, 1);
    }

    public function test_cancel_fails_when_the_record_cannot_be_removed(): void
    {
        $this->useDisk(deleteFails: true);

        $this->writeFreeze(ContentFreezeService::STATUS_SCHEDULED);

        $result = (new ContentFreezeService)->cancel('u1');

        $this->assertFalse($result['ok']);
        $this->assertNotNull((new ContentFreezeService)->current());
    }

    /**
     * The completed record is saved before the all-clear goes, so a record
     * that can't then be removed reads as finished: no banner, and a second
     * Complete has nothing to complete (it used to send another all-clear).
     */
    public function test_a_completed_record_that_cannot_be_removed_is_not_completed_again(): void
    {
        $this->useDisk(deleteFails: true);
        Mail::fake();

        $this->writeFreeze(ContentFreezeService::STATUS_ACTIVE);

        $service = new ContentFreezeService;
        $this->assertTrue($service->complete('u1')['ok']);
        $this->assertNull($service->current());
        $this->assertSame('No update to complete.', $service->complete('u1')['message']);
        Mail::assertQueued(FreezeCompletionMail::class, 1);
    }

    protected function writeFreeze(string $status): void
    {
        Storage::disk('local')->put(ContentFreezeService::CURRENT_PATH, json_encode([
            'id'         => 'abc123',
            'status'     => $status,
            'notify_at'  => Carbon::now()->subHours(2)->toIso8601String(),
            'freeze_at'  => Carbon::now()->subHour()->toIso8601String(),
            'recipients' => ['editor@example.com'],
        ]));
    }

    protected function assertWriteThrows(string $path, string $contents): void
    {
        try {
            AtomicFile::put($path, $contents);
        } catch (\Throwable $e) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('AtomicFile::put() should have thrown');
    }

    /**
     * Swap the local disk for a real local disk under a temp root.
     *
     * - $localPaths false: path() throws, like a non-local adapter, to force
     *   the move() fallback.
     * - $putFails 'nothing': put() returns false without writing, as when the
     *   temp can't be created. 'partial': it writes half the contents and then
     *   returns false, as on a full disk.
     * - $deleteFails: delete() returns false and leaves the file.
     */
    protected function useDisk(bool $localPaths = true, ?string $putFails = null, bool $deleteFails = false): void
    {
        $base = Storage::build(['driver' => 'local', 'root' => $this->root]);

        $disk = new class($base->getDriver(), $base->getAdapter(), $base->getConfig(), $localPaths, $putFails, $deleteFails) extends FilesystemAdapter {
            public function __construct($driver, $adapter, array $config, protected bool $localPaths, protected ?string $putFails, protected bool $deleteFails)
            {
                parent::__construct($driver, $adapter, $config);
            }

            public function put($path, $contents, $options = [])
            {
                if ($this->putFails === 'nothing') {
                    return false;
                }

                if ($this->putFails === 'partial') {
                    parent::put($path, substr((string) $contents, 0, intdiv(strlen((string) $contents), 2)), $options);

                    return false;
                }

                return parent::put($path, $contents, $options);
            }

            public function delete($paths)
            {
                if ($this->deleteFails && $paths === ContentFreezeService::CURRENT_PATH) {
                    return false;
                }

                return parent::delete($paths);
            }

            public function path($path)
            {
                if (! $this->localPaths) {
                    throw new \BadMethodCallException('No local path');
                }

                return parent::path($path);
            }
        };

        Storage::set('local', $disk);
    }
}
