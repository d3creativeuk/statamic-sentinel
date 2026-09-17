<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Console\Commands\ScanCommand;
use D3Creative\Sentinel\Services\AuditService;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use ReflectionMethod;
use ReflectionProperty;

class ScanRobustnessTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * json("packages.{$name}") uses dot notation, so a name with a dot
     * resolved to nothing and the package was never checked for updates.
     */
    public function test_packagist_names_with_dots_are_read(): void
    {
        $service = Mockery::mock(AuditService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('composerInstalledDirect')->andReturn(['mtdowling/jmespath.php' => '2.6.0']);

        Http::fake(['repo.packagist.org/p2/mtdowling/jmespath.php.json' => Http::response([
            'packages' => ['mtdowling/jmespath.php' => [['version' => '2.8.0']]],
        ])]);

        $method = new ReflectionMethod($service, 'composerOutdated');
        $method->setAccessible(true);

        $this->assertSame([['name' => 'mtdowling/jmespath.php', 'current' => '2.6.0', 'latest' => '2.8.0']], $method->invoke($service)['packages']);
    }

    /**
     * An alias was looked up under its alias (possibly someone else's
     * package), and file:/workspace:/git dependencies under their local name.
     */
    public function test_npm_aliases_use_the_real_package_and_local_specs_are_skipped(): void
    {
        $service = new AuditService;
        $cache   = new ReflectionProperty($service, 'lockfileCache');
        $cache->setAccessible(true);
        $cache->setValue($service, [base_path('package.json') => [
            'dependencies' => [
                'vue2'         => 'npm:vue@^2.7.0',
                'scoped-alias' => 'npm:@scope/real@1.0.0',
                'local-lib'    => 'file:../lib',
                'ws-pkg'       => 'workspace:*',
                'from-git'     => 'github:acme/thing#main',
                'shorthand'    => 'acme/thing',
                'plain'        => '^1.2.3',
            ],
        ]]);

        $method = new ReflectionMethod($service, 'npmRegistryNames');
        $method->setAccessible(true);

        $this->assertSame(
            ['vue2' => 'vue', 'scoped-alias' => '@scope/real', 'plain' => 'plain'],
            $method->invoke($service, ['vue2', 'scoped-alias', 'local-lib', 'ws-pkg', 'from-git', 'shorthand', 'plain'])
        );
    }

    public function test_cached_falls_back_to_disk_when_the_cache_is_down(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(AuditService::DISK_PATH, json_encode(['audited_at' => 'from disk']));

        Cache::shouldReceive('get')->andThrow(new \RuntimeException('Connection refused'));
        Cache::shouldReceive('forever')->andThrow(new \RuntimeException('Connection refused'));

        $service = Mockery::mock(AuditService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('reconcileAgainstLive')->andReturnUsing(fn ($audit) => $audit);

        $this->assertSame('from disk', $service->cached()['audited_at']);
    }

    public function test_scan_command_fails_when_a_check_failed(): void
    {
        $audit = Mockery::mock(AuditService::class);
        $audit->shouldReceive('refresh')->andReturn([
            'composer' => ['status' => 'error', 'message' => 'Could not reach vulnerability database.', 'total_vulns' => 0, 'outdated' => ['total' => 0]],
            'npm'      => ['status' => 'ok', 'total_vulns' => 0, 'outdated' => ['total' => 0, 'error' => true]],
        ]);
        $this->app->instance(AuditService::class, $audit);
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand($this->app->make(ScanCommand::class));

        $this->artisan('sentinel:scan')
            ->expectsOutputToContain('Composer vulnerability check failed')
            ->expectsOutputToContain('npm update check failed')
            ->assertExitCode(1);
    }
}
