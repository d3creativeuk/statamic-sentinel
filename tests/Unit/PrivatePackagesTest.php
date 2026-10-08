<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Services\AuditService;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;

/**
 * A package installed from a private source was compared with the public
 * package of the same name, so whoever registered that name on Packagist or
 * npm set the "latest version" Sentinel reported, and could mark a release
 * as a vendor security update: the prompt to install their package that a
 * dependency-confusion attack needs.
 */
class PrivatePackagesTest extends TestCase
{
    protected array $files = ['composer.json', 'composer.lock', 'package.json', 'package-lock.json', '.npmrc'];

    protected array $backups = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Storage::fake('local');

        foreach ($this->files as $file) {
            if (is_file(base_path($file))) {
                $this->backups[$file] = file_get_contents(base_path($file));
            }
        }

        putenv('HOME=/');
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink(base_path($file));

            if (isset($this->backups[$file])) {
                file_put_contents(base_path($file), $this->backups[$file]);
            }
        }

        putenv('HOME');

        parent::tearDown();
    }

    public function test_path_and_vcs_installs_are_not_looked_up_on_packagist(): void
    {
        $this->writeComposer(
            ['acme/local' => '*', 'acme/vcs' => '^1.0', 'monolog/monolog' => '^3.0'],
            [
                ['type' => 'path', 'url' => '../packages/local'],
                ['type' => 'vcs', 'url' => 'git@github.com:acme/vcs.git'],
            ],
            [
                ['name' => 'acme/local', 'version' => '1.0.0', 'dist' => ['type' => 'path', 'url' => '../packages/local']],
                ['name' => 'acme/vcs', 'version' => '1.2.0', 'source' => ['type' => 'git', 'url' => 'https://github.com/Acme/vcs']],
                ['name' => 'monolog/monolog', 'version' => '3.0.0', 'source' => ['type' => 'git', 'url' => 'https://github.com/Seldaek/monolog.git']],
            ]
        );

        Http::fake([
            'repo.packagist.org/p2/*' => Http::response(['packages' => [
                'acme/local'      => [['version' => '9.9.9']],
                'acme/vcs'        => [['version' => '9.9.9']],
                'monolog/monolog' => [['version' => '3.1.0']],
            ]]),
        ]);

        $result = $this->invokeAudit(new AuditService, 'composerOutdated');

        $this->assertSame(['monolog/monolog'], array_column($result['packages'], 'name'));
        $this->assertEqualsCanonicalizing(['acme/local', 'acme/vcs'], $result['private']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'acme'));
    }

    /**
     * Mirrors (Tencent, Aliyun) serve public packages from another host, so
     * a non-Packagist dist URL alone mustn't make a package private.
     */
    public function test_a_package_from_a_mirror_is_still_checked(): void
    {
        $this->writeComposer(['monolog/monolog' => '^3.0'], [], [
            ['name' => 'monolog/monolog', 'version' => '3.0.0', 'dist' => ['type' => 'zip', 'url' => 'https://mirrors.tencent.com/composer/monolog.zip']],
        ]);

        Http::fake(['repo.packagist.org/p2/*' => Http::response(['packages' => ['monolog/monolog' => [['version' => '3.1.0']]]])]);

        $result = $this->invokeAudit(new AuditService, 'composerOutdated');

        $this->assertSame(['monolog/monolog'], array_column($result['packages'], 'name'));
        $this->assertArrayNotHasKey('private', $result);
    }

    public function test_a_private_addon_is_not_asked_about_on_the_marketplace(): void
    {
        $this->writeComposer(['acme/addon' => '*'], [['type' => 'path', 'url' => 'addons/acme']], [
            ['name' => 'acme/addon', 'version' => '1.0.0', 'dist' => ['type' => 'path'], 'extra' => ['statamic' => ['name' => 'Acme']]],
        ]);

        $this->assertFalse($this->invokeAudit(new AuditService, 'isMarketplacePackage', ['acme/addon']));
    }

    public function test_an_npm_scope_on_another_registry_is_not_looked_up_on_npm(): void
    {
        file_put_contents(base_path('.npmrc'), "@acme:registry=https://npm.acme.dev/\n@public:registry=https://registry.npmjs.org/\n");
        file_put_contents(base_path('package.json'), json_encode(['dependencies' => ['@acme/ui' => '^1.0.0', '@public/kit' => '^1.0.0']]));
        file_put_contents(base_path('package-lock.json'), json_encode(['lockfileVersion' => 3, 'packages' => [
            'node_modules/@acme/ui'    => ['version' => '1.0.0'],
            'node_modules/@public/kit' => ['version' => '1.0.0'],
        ]]));

        Http::fake(['registry.npmjs.org/*' => Http::response(['version' => '2.0.0', 'time' => []])]);

        $result = $this->invokeAudit(new AuditService, 'npmOutdated');

        $this->assertSame(['@public/kit'], array_column($result['packages'], 'name'));
        $this->assertSame(['@acme/ui'], $result['private']);
        Http::assertNotSent(fn (Request $r) => str_contains(urldecode($r->url()), '@acme'));
    }

    /**
     * Private Packagist and Satis installs look like any other package in
     * the lock, so they're listed in config, and those names stay home.
     */
    public function test_configured_private_packages_are_left_out_of_every_lookup(): void
    {
        config(['statamic-sentinel.private_packages' => ['acme/*', '@acme/*']]);

        $this->writeComposer(['acme/billing' => '^1.0'], [], [
            ['name' => 'acme/billing', 'version' => '1.0.0'],
            ['name' => 'monolog/monolog', 'version' => '3.0.0'],
        ]);
        file_put_contents(base_path('package-lock.json'), json_encode(['lockfileVersion' => 3, 'packages' => [
            'node_modules/@acme/ui' => ['version' => '1.0.0'],
            'node_modules/lodash'   => ['version' => '4.17.21'],
        ]]));

        Http::fake([
            'api.osv.dev/v1/querybatch' => Http::response(['results' => [['vulns' => []]]]),
            '*'                         => Http::response([], 404),
        ]);

        $service = new AuditService;
        $this->invokeAudit($service, 'composerAudit');
        $this->invokeAudit($service, 'npmAudit');
        $outdated = $this->invokeAudit($service, 'composerOutdated');

        $this->assertSame(['acme/billing'], $outdated['private']);

        $osvNames = [];
        Http::assertSent(function (Request $r) use (&$osvNames) {
            if (str_contains($r->url(), 'querybatch')) {
                foreach ($r->data()['queries'] ?? [] as $q) {
                    $osvNames[] = $q['package']['name'];
                }
            }

            $this->assertStringNotContainsString('acme', urldecode($r->url()));

            return true;
        });

        $this->assertEqualsCanonicalizing(['monolog/monolog', 'lodash'], $osvNames);
    }

    public function test_repository_urls_compare_across_forms(): void
    {
        $service = new AuditService;

        foreach (['git@github.com:Acme/x.git', 'https://github.com/acme/x', 'ssh://git@github.com/acme/x.git', 'https://github.com/acme/x.git/'] as $url) {
            $this->assertSame('github.com/acme/x', $this->invokeAudit($service, 'normaliseRepoUrl', [$url]));
        }

        $this->assertSame('git.acme.dev:2222/acme/x', $this->invokeAudit($service, 'normaliseRepoUrl', ['ssh://git@git.acme.dev:2222/acme/x.git']));
    }

    protected function writeComposer(array $require, array $repositories, array $packages): void
    {
        file_put_contents(base_path('composer.json'), json_encode(['require' => $require, 'repositories' => $repositories]));
        file_put_contents(base_path('composer.lock'), json_encode(['packages' => $packages, 'packages-dev' => []]));
    }

    protected function invokeAudit(AuditService $service, string $method, array $args = [])
    {
        $m = new ReflectionMethod($service, $method);
        $m->setAccessible(true);

        return $m->invoke($service, ...$args);
    }
}
