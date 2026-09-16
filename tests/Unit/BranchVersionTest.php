<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Services\AuditService;
use D3Creative\Sentinel\Services\MarketplaceService;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Mockery;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Branch installs (dev-main, 2.x-dev) aren't versions. version_compare()
 * treats them as older than every release, so they showed as outdated
 * forever, drew a vendor "security update" from every marketplace release,
 * and OSV matched them against every historic advisory.
 */
class BranchVersionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_branch_installs_are_not_listed_as_outdated(): void
    {
        $service = Mockery::mock(AuditService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('composerInstalledDirect')->andReturn([
            'acme/on-branch' => 'dev-main',
            'acme/on-alias'  => '2.x-dev',
            'acme/tagged'    => '1.0.0',
        ]);

        Http::fake(['repo.packagist.org/p2/*' => fn ($request) => Http::response([
            'packages' => [str_replace(['https://repo.packagist.org/p2/', '.json'], '', $request->url()) => [['version' => '3.0.0']]],
        ])]);

        $result = $this->invoke($service, 'composerOutdated');

        $this->assertSame(['acme/tagged'], array_column($result['packages'], 'name'));
    }

    public function test_branch_installs_are_not_sent_to_osv(): void
    {
        Http::fake(['api.osv.dev/v1/querybatch' => Http::response(['results' => [[]]])]);

        $service = new AuditService;
        $cache   = new ReflectionProperty($service, 'lockfileCache');
        $cache->setAccessible(true);
        $cache->setValue($service, [base_path('composer.lock') => ['packages' => [
            ['name' => 'laravel/framework', 'version' => 'dev-master'],
            ['name' => 'acme/tagged', 'version' => 'v1.2.3'],
        ]]]);

        $result = $this->invoke($service, 'composerAudit');

        $this->assertSame(1, $result['total_packages']);
        Http::assertSent(fn ($request) => $request['queries'] === [
            ['package' => ['name' => 'acme/tagged', 'ecosystem' => 'Packagist'], 'version' => '1.2.3'],
        ]);
    }

    public function test_marketplace_has_no_releases_after_a_branch_install(): void
    {
        Http::fake();

        $marketplace = new MarketplaceService;

        $this->assertSame([], $marketplace->releasesAfter('statamic/cms', 'dev-master'));
        $this->assertSame([], $marketplace->releasesAfter('statamic/cms', '6.x-dev'));
        $this->assertFalse($marketplace->hasSecurityReleaseAfter('acme/seo', 'dev-main'));
        Http::assertNothingSent();
    }

    protected function invoke($service, string $method)
    {
        $reflection = new ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($service);
    }
}
