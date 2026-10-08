<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Services\AuditService;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;

/**
 * When the cache refused a new audit (a root-owned cache file, DynamoDB's
 * 400 KB item limit, a read-only replica) but still held the previous one,
 * every reader got the old scan while audit.json had the new one, so Refresh
 * looked like it did nothing.
 */
class StaleAuditCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Storage::fake('local');
    }

    public function test_a_newer_disk_mirror_wins_over_a_stale_cache(): void
    {
        Cache::forever(AuditService::CACHE_KEY, $this->audit('old', time() - 3600));
        $this->writeDisk($this->audit('new', time()), time());

        $this->assertSame('new', (new AuditService)->cached()['audited_at']);
    }

    public function test_a_current_cache_is_used_without_reading_the_disk(): void
    {
        $now = time();
        Cache::forever(AuditService::CACHE_KEY, $this->audit('cached', $now));
        $this->writeDisk($this->audit('disk', $now), $now + 2);

        $this->assertSame('cached', (new AuditService)->cached()['audited_at']);
    }

    public function test_an_audit_cached_before_scanned_at_existed_is_used_as_before(): void
    {
        $legacy = $this->audit('legacy', 0);
        unset($legacy['scanned_at']);
        Cache::forever(AuditService::CACHE_KEY, $legacy);
        $this->writeDisk($this->audit('disk', time()), time());

        $this->assertSame('legacy', (new AuditService)->cached()['audited_at']);
    }

    public function test_a_refused_write_clears_the_old_value_and_stops_retries(): void
    {
        Cache::forever(AuditService::CACHE_KEY, $this->audit('old', time() - 3600));
        $this->refuseWrites();

        $this->store($this->audit('new', time()));
        $this->writeDisk($this->audit('new', time()), time());

        $this->assertNull(Cache::get(AuditService::CACHE_KEY));
        $this->assertTrue(Cache::has(AuditService::CACHE_UNWRITABLE_KEY));

        // Reads serve the disk copy and don't try to write it back.
        $this->assertSame('new', (new AuditService)->cached()['audited_at']);
        $this->assertSame(1, $this->rejectedWrites);
    }

    public int $rejectedWrites = 0;

    protected function refuseWrites(): void
    {
        $test  = $this;
        $store = new class($test) extends ArrayStore {
            public function __construct(private $test)
            {
                parent::__construct();
            }

            public function forever($key, $value)
            {
                if ($key === AuditService::CACHE_KEY) {
                    $this->test->rejectedWrites++;

                    return false;
                }

                return parent::forever($key, $value);
            }
        };
        $store->forever(AuditService::CACHE_KEY . '_seed', 1);

        Cache::swap(new Repository($store));
        $store->put(AuditService::CACHE_KEY, $this->audit('old', time() - 3600), 3600);
    }

    protected function store(array $audit): void
    {
        $service = new AuditService;
        $method  = new ReflectionMethod($service, 'storeInCache');
        $method->setAccessible(true);
        $method->invoke($service, $audit);
    }

    protected function writeDisk(array $audit, int $mtime): void
    {
        Storage::disk('local')->put(AuditService::DISK_PATH, json_encode($audit));
        touch(Storage::disk('local')->path(AuditService::DISK_PATH), $mtime);
        clearstatcache();
    }

    protected function audit(string $label, int $scannedAt): array
    {
        return ['audited_at' => $label, 'scanned_at' => $scannedAt, 'composer' => [], 'npm' => []];
    }
}
