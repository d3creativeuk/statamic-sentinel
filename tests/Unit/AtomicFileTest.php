<?php

namespace D3Creative\Sentinel\Tests\Unit;

use Carbon\Carbon;
use D3Creative\Sentinel\Mail\FreezeNotificationMail;
use D3Creative\Sentinel\Services\ContentFreezeService;
use D3Creative\Sentinel\Services\ScheduleService;
use D3Creative\Sentinel\Support\AtomicFile;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Regression coverage for Laravel 8 (Statamic 3.3), where Flysystem 1's
 * rename() throws FileExistsException when the target exists. Every JSON
 * store used Storage::move() over its existing file, so each one stopped
 * updating after its first write, and a freeze stuck at `scheduled` re-sent
 * its heads-up email every minute.
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

    public function test_replaces_an_existing_file_when_move_refuses_to_overwrite(): void
    {
        $this->useLaravel8Disk();

        AtomicFile::put('statamic-sentinel/a.json', '{"v":1}');
        AtomicFile::put('statamic-sentinel/a.json', '{"v":2}');

        $this->assertSame('{"v":2}', Storage::disk('local')->get('statamic-sentinel/a.json'));
        $this->assertSame(['statamic-sentinel/a.json'], Storage::disk('local')->allFiles());
    }

    public function test_falls_back_to_delete_and_move_on_a_disk_without_local_paths(): void
    {
        $this->useLaravel8Disk(false);

        AtomicFile::put('statamic-sentinel/a.json', '{"v":1}');
        AtomicFile::put('statamic-sentinel/a.json', '{"v":2}');

        $this->assertSame('{"v":2}', Storage::disk('local')->get('statamic-sentinel/a.json'));
        $this->assertSame(['statamic-sentinel/a.json'], Storage::disk('local')->allFiles());
    }

    public function test_services_keep_saving_after_their_first_write(): void
    {
        $this->useLaravel8Disk();

        $schedules = new ScheduleService;
        $config    = $schedules->defaults();

        $this->assertTrue($schedules->save($config));

        $config['status_report']['time'] = '17:30';
        $this->assertTrue($schedules->save($config));

        $this->assertSame('17:30', $schedules->all()['status_report']['time']);
    }

    /**
     * The production symptom: with the state write failing, the freeze stayed
     * `scheduled` and every tick sent the heads-up email again.
     */
    public function test_a_freeze_advances_after_its_heads_up_so_it_is_sent_once(): void
    {
        $this->useLaravel8Disk();
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

    /**
     * Swap the local disk for one that behaves like Laravel 8: move() throws
     * when the target exists. With $localPaths off, path() throws too, like a
     * non-local adapter, to force the Storage fallback.
     */
    protected function useLaravel8Disk(bool $localPaths = true): void
    {
        $base = Storage::build(['driver' => 'local', 'root' => $this->root]);

        $disk = new class($base->getDriver(), $base->getAdapter(), $base->getConfig(), $localPaths) extends FilesystemAdapter {
            public function __construct($driver, $adapter, array $config, protected bool $localPaths)
            {
                parent::__construct($driver, $adapter, $config);
            }

            public function move($from, $to)
            {
                if ($this->exists($to)) {
                    throw new \RuntimeException("File already exists at path: {$to}");
                }

                return parent::move($from, $to);
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
