<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Services\AuditService;
use D3Creative\Sentinel\Tests\TestCase;
use ReflectionMethod;

/**
 * Every dashboard and utility render reconciles the cached scan against the
 * live lock files. When a lock file hasn't changed since the scan, that's a
 * no-op, so it's skipped instead of decoding the file on every render.
 */
class LockFingerprintTest extends TestCase
{
    protected function tearDown(): void
    {
        @unlink(base_path('composer.lock'));

        parent::tearDown();
    }

    public function test_an_unchanged_lock_file_is_not_reconciled(): void
    {
        $this->writeLock('7.0.0', time() - 60);

        // The cached list says acme/pkg is outdated at 1.0.0; the live lock
        // (unchanged since the scan, by fingerprint) is not read at all.
        $audit  = $this->audit($this->fingerprint());
        $result = $this->reconcile($audit);

        $this->assertSame(1, $result['composer']['outdated']['total']);
    }

    public function test_a_changed_lock_file_is_reconciled(): void
    {
        $this->writeLock('2.0.0', time() - 60);
        $before = $this->fingerprint();

        $this->writeLock('2.0.0', time());
        $result = $this->reconcile($this->audit($before));

        // Live acme/pkg 2.0.0 has reached latest: dropped from the list, and
        // Statamic's version comes from the same decoded lock.
        $this->assertSame(0, $result['composer']['outdated']['total']);
        $this->assertSame('6.1.0', $result['statamic']['current']);
    }

    public function test_an_audit_without_fingerprints_reconciles_as_before(): void
    {
        $this->writeLock('2.0.0', time());
        $audit = $this->audit(null);
        unset($audit['lock_fingerprints']);

        $this->assertSame(0, $this->reconcile($audit)['composer']['outdated']['total']);
    }

    protected function writeLock(string $pkgVersion, int $mtime): void
    {
        file_put_contents(base_path('composer.lock'), json_encode(['packages' => [
            ['name' => 'statamic/cms', 'version' => 'v6.1.0'],
            ['name' => 'acme/pkg', 'version' => $pkgVersion],
        ], 'packages-dev' => []]));
        touch(base_path('composer.lock'), $mtime);
        clearstatcache();
    }

    protected function fingerprint(): string
    {
        $m = new ReflectionMethod(AuditService::class, 'lockFingerprint');
        $m->setAccessible(true);

        return $m->invoke(new AuditService, 'composer.lock');
    }

    protected function reconcile(array $audit): array
    {
        $m = new ReflectionMethod(AuditService::class, 'reconcileAgainstLive');
        $m->setAccessible(true);

        return $m->invoke(new AuditService, $audit);
    }

    protected function audit(?string $composerFingerprint): array
    {
        return [
            'statamic'          => ['current' => '6.0.0', 'latest' => '6.1.0', 'is_latest' => false, 'status' => 'outdated'],
            'composer'          => ['outdated' => ['total' => 1, 'packages' => [['name' => 'acme/pkg', 'current' => '1.0.0', 'latest' => '2.0.0']]]],
            'npm'               => ['outdated' => ['total' => 0, 'packages' => []]],
            'lock_fingerprints' => ['composer' => $composerFingerprint, 'npm' => 'missing'],
        ];
    }
}
