<?php

namespace D3Creative\Sentinel\Tests\Unit;

use Carbon\Carbon;
use D3Creative\Sentinel\Services\LastActiveService;
use D3Creative\Sentinel\ServiceProvider;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Statamic\Facades\User;

/**
 * The Users tab used to load every Statamic user, front-end members
 * included, and look up each one's last login and permissions on every
 * super's utility load.
 */
class UsersTabTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Storage::fake('local');
    }

    public function test_only_control_panel_users_are_listed(): void
    {
        $this->fakeUsers([
            $this->user('super', super: true),
            $this->user('editor', cp: true),
            $this->user('member'),
        ], count: 3);

        $this->assertSame(['editor', 'super'], $this->listedIds());
    }

    /**
     * On a big membership site the tab starts from recently active users
     * (only CP users are ever recorded) plus the viewer, rather than loading
     * thousands of members.
     */
    public function test_a_large_site_lists_recently_active_cp_users_without_loading_everyone(): void
    {
        Storage::disk('local')->put('statamic-sentinel/last-active.json', json_encode(['editor' => Carbon::now()->toIso8601String()]));

        $repo = $this->fakeUsers([
            $this->user('super', super: true),
            $this->user('editor', cp: true),
            $this->user('member'),
        ], count: 5000);

        $ids = $this->listedIds();

        $this->assertSame(['editor', 'super'], $ids);
        $this->assertFalse($repo->allCalled);
    }

    protected function listedIds(): array
    {
        $m = new ReflectionMethod(ServiceProvider::class, 'buildUserActivity');
        $m->setAccessible(true);
        $ids = array_column($m->invoke(new ServiceProvider($this->app)), 'id');
        sort($ids);

        return $ids;
    }

    protected function user(string $id, bool $super = false, bool $cp = false)
    {
        return new class($id, $super, $cp) {
            public function __construct(private string $id, private bool $super, private bool $cp)
            {
            }

            public function id() { return $this->id; }
            public function name() { return ucfirst($this->id); }
            public function email() { return "{$this->id}@example.test"; }
            public function isSuper() { return $this->super; }
            public function hasPermission($p) { return $this->super || ($this->cp && $p === 'access cp'); }
            public function lastLogin() { return null; }
        };
    }

    protected function fakeUsers(array $users, int $count)
    {
        $repo = new class(collect($users), $count) {
            public bool $allCalled = false;

            public function __construct(public $users, private int $count)
            {
            }

            public function all() { $this->allCalled = true; return $this->users; }
            public function find($id) { return $this->users->first(fn ($u) => $u->id() === $id); }
            public function query() { $count = $this->count; return new class($count) { public function __construct(private int $n) {} public function count() { return $this->n; } }; }
            // CurrentUser::id(): the viewer is the super.
            public function current() { return $this->find('super'); }
        };

        User::swap($repo);

        return $repo;
    }
}
