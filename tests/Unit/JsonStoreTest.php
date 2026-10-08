<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Services\HistoryService;
use D3Creative\Sentinel\Services\PackageNoteService;
use D3Creative\Sentinel\Services\SentMailService;
use D3Creative\Sentinel\Support\JsonStore;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * A store that couldn't be read used to read as empty, and the next routine
 * write replaced it with only its newest entry, so a recoverable file (or a
 * passing read error) became permanent loss.
 */
class JsonStoreTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/sentinel-jsonstore-' . uniqid();
        File::ensureDirectoryExists($this->root);
        $this->useDisk();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_a_corrupt_history_is_moved_aside_before_the_next_write(): void
    {
        $truncated = substr(json_encode([['recorded_at' => '2026-01-01T00:00:00+00:00', 'statamic' => '5.0.0']]), 0, -10);
        Storage::disk('local')->put(HistoryService::RELATIVE_PATH, $truncated);

        (new HistoryService)->recordIfChanged($this->audit());

        $this->assertCount(1, (new HistoryService)->all());

        $aside = $this->corruptCopies(HistoryService::RELATIVE_PATH);
        $this->assertCount(1, $aside);
        $this->assertSame($truncated, Storage::disk('local')->get($aside[0]));
    }

    public function test_a_corrupt_sent_log_is_moved_aside_before_the_next_write(): void
    {
        Storage::disk('local')->put(SentMailService::INDEX_PATH, '[{"id":"abc"');

        $this->assertNotNull((new SentMailService)->record(SentMailService::KIND_STATUS, ['a@b.test'], 'manual', SentMailService::OUTCOME_SENT));
        $this->assertCount(1, $this->corruptCopies(SentMailService::INDEX_PATH));
    }

    /**
     * A read that fails (rather than finding bad JSON) aborts the write: the
     * file may be fine, so it mustn't be replaced or moved.
     */
    public function test_an_unreadable_store_is_left_alone(): void
    {
        $original = json_encode(['npm' => ['braces' => ['note' => 'Waiting on Tailwind 4.']]]);
        Storage::disk('local')->put(PackageNoteService::RELATIVE_PATH, $original);

        $this->useDisk(getFails: true);

        $this->assertFalse((new PackageNoteService)->set('npm', 'postcss', 'Another note', 'u1'));

        $this->useDisk();
        $this->assertSame($original, Storage::disk('local')->get(PackageNoteService::RELATIVE_PATH));
        $this->assertSame([], $this->corruptCopies(PackageNoteService::RELATIVE_PATH));
    }

    public function test_a_display_read_of_a_corrupt_store_leaves_it_in_place(): void
    {
        Storage::disk('local')->put(HistoryService::RELATIVE_PATH, '{"not json');

        $this->assertSame([], (new HistoryService)->all());
        $this->assertSame([], JsonStore::read(HistoryService::RELATIVE_PATH));
        $this->assertSame('{"not json', Storage::disk('local')->get(HistoryService::RELATIVE_PATH));
    }

    public function test_a_missing_store_reads_as_empty_for_writing(): void
    {
        $this->assertSame([], JsonStore::read('statamic-sentinel/missing.json', true));
    }

    /**
     * @return array<int, string>
     */
    protected function corruptCopies(string $path): array
    {
        return array_values(array_filter(
            Storage::disk('local')->allFiles(dirname($path)),
            fn ($f) => str_starts_with($f, $path . '.corrupt-')
        ));
    }

    protected function audit(): array
    {
        return [
            'statamic' => ['current' => '6.0.0'],
            'laravel'  => ['version' => '12.0.0'],
            'php'      => ['version' => '8.4.0'],
            'composer' => ['status' => 'ok', 'total_vulns' => 0, 'outdated' => ['total' => 0]],
            'npm'      => ['status' => 'ok', 'total_vulns' => 0, 'outdated' => ['total' => 0]],
        ];
    }

    /**
     * A real local disk under a temp root; with $getFails, get() returns null
     * as Laravel's local disk does when a read fails.
     */
    protected function useDisk(bool $getFails = false): void
    {
        $base = Storage::build(['driver' => 'local', 'root' => $this->root]);

        Storage::set('local', new class($base->getDriver(), $base->getAdapter(), $base->getConfig(), $getFails) extends FilesystemAdapter {
            public function __construct($driver, $adapter, array $config, protected bool $getFails)
            {
                parent::__construct($driver, $adapter, $config);
            }

            public function get($path)
            {
                return $this->getFails ? null : parent::get($path);
            }
        });
    }
}
