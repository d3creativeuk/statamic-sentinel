<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Services\AuditService;
use D3Creative\Sentinel\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * On hosts with open_basedir (HestiaCP's default pool, for one) the home
 * .npmrc sits outside the allowed paths. is_file() on it raised a warning,
 * Laravel turned that into an exception, and every scan started from the
 * Control Panel failed. Each test runs in its own process because
 * open_basedir can only be tightened, never relaxed.
 */
class NpmrcProbeTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_home_outside_open_basedir_is_skipped(): void
    {
        putenv('HOME=/');
        $this->restrictToProject();

        $this->assertSame(0, $this->minReleaseAge());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_an_unset_home_is_skipped(): void
    {
        putenv('HOME');
        $this->restrictToProject();

        $this->assertSame(0, $this->minReleaseAge());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_project_npmrc_is_still_read(): void
    {
        $npmrc = base_path('.npmrc');
        file_put_contents($npmrc, "min-release-age=7\n");

        try {
            putenv('HOME=/');
            $this->restrictToProject();

            $this->assertSame(7, $this->minReleaseAge());
        } finally {
            @unlink($npmrc);
        }
    }

    protected function restrictToProject(): void
    {
        $allowed = [
            dirname(__DIR__, 2),
            sys_get_temp_dir(),
            realpath(sys_get_temp_dir()) ?: sys_get_temp_dir(),
        ];

        ini_set('open_basedir', implode(PATH_SEPARATOR, array_unique($allowed)));
    }

    protected function minReleaseAge(): int
    {
        $method = new \ReflectionMethod(AuditService::class, 'npmMinReleaseAgeDays');
        $method->setAccessible(true);

        return $method->invoke(new AuditService);
    }
}
