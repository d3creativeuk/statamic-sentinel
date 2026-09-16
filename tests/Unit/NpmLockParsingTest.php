<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Services\AuditService;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;

/**
 * The npm vulnerability check sends every installed package to OSV. Nested
 * copies used to go out named by their whole install path
 * (`chokidar/node_modules/glob-parent`), which OSV never matches, so old
 * vulnerable copies deep in the tree were never reported. Every lockfile on
 * a sample of real sites had some.
 *
 * @see AuditService::npmLockPackages()
 */
class NpmLockParsingTest extends TestCase
{
    public function test_v3_nested_copies_are_named_by_package_not_path(): void
    {
        $packages = $this->parse(['lockfileVersion' => 3, 'packages' => [
            ''                                              => ['name' => 'site', 'version' => '1.0.0'],
            'node_modules/glob-parent'                      => ['version' => '6.0.2'],
            'node_modules/chokidar/node_modules/glob-parent' => ['version' => '5.1.2'],
            'node_modules/@scope/a/node_modules/@scope/b'   => ['version' => '2.0.0'],
        ]]);

        $this->assertSame(['glob-parent@6.0.2', 'glob-parent@5.1.2', '@scope/b@2.0.0'], array_keys($packages));
        $this->assertSame(['name' => 'glob-parent', 'version' => '5.1.2'], $packages['glob-parent@5.1.2']);
    }

    public function test_v3_aliases_use_the_real_package_name(): void
    {
        $packages = $this->parse(['packages' => [
            'node_modules/string-width-cjs' => ['name' => 'string-width', 'version' => '4.2.3'],
        ]]);

        $this->assertSame(['string-width@4.2.3'], array_keys($packages));
    }

    public function test_workspaces_links_and_non_registry_versions_are_skipped(): void
    {
        $packages = $this->parse(['packages' => [
            'packages/app'          => ['name' => 'app', 'version' => '0.0.1'],
            'node_modules/app'      => ['resolved' => 'packages/app', 'link' => true],
            'node_modules/from-git' => ['version' => 'git+https://github.com/acme/x.git#abc'],
            'node_modules/real'     => ['version' => '1.2.3'],
        ]]);

        $this->assertSame(['real@1.2.3'], array_keys($packages));
    }

    public function test_v1_nested_dependencies_and_aliases_are_walked(): void
    {
        $packages = $this->parse(['lockfileVersion' => 1, 'dependencies' => [
            'chokidar' => [
                'version'      => '2.1.8',
                'dependencies' => [
                    'glob-parent' => ['version' => '3.1.0'],
                ],
            ],
            'string-width-cjs' => ['version' => 'npm:string-width@4.2.3'],
            '@scoped-alias'    => ['version' => 'npm:@scope/real@1.0.0'],
        ]]);

        $this->assertSame(
            ['chokidar@2.1.8', 'glob-parent@3.1.0', 'string-width@4.2.3', '@scope/real@1.0.0'],
            array_keys($packages)
        );
    }

    public function test_an_advisory_on_two_installed_versions_is_counted_once(): void
    {
        Http::fake([
            'api.osv.dev/v1/querybatch' => Http::response(['results' => [
                ['vulns' => [['id' => 'GHSA-glob', 'modified' => '2026-01-01T00:00:00Z']]],
                ['vulns' => [['id' => 'GHSA-glob', 'modified' => '2026-01-01T00:00:00Z']]],
            ]]),
            'api.osv.dev/v1/vulns/*' => Http::response(['id' => 'GHSA-glob', 'database_specific' => ['severity' => 'HIGH']]),
        ]);

        $service = new AuditService;
        $method  = new ReflectionMethod($service, 'queryOsv');
        $method->setAccessible(true);

        $result = $method->invoke($service, [
            ['package' => ['name' => 'glob-parent', 'ecosystem' => 'npm'], 'version' => '5.1.2'],
            ['package' => ['name' => 'glob-parent', 'ecosystem' => 'npm'], 'version' => '3.1.0'],
        ], 2);

        $this->assertSame(1, $result['total_vulns']);
        $this->assertSame(1, $result['by_package'][0]['count']);
    }

    protected function parse(array $lock): array
    {
        $service = new AuditService;
        $method  = new ReflectionMethod($service, 'npmLockPackages');
        $method->setAccessible(true);

        return $method->invoke($service, $lock);
    }
}
