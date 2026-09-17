<?php

namespace D3Creative\Sentinel\Tests\Unit;

use D3Creative\Sentinel\Http\Controllers\HistoryActionController;
use D3Creative\Sentinel\Support\CpAccess;
use D3Creative\Sentinel\Tests\TestCase;
use Illuminate\Http\Request;
use Mockery;
use Statamic\Facades\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SecurityGuardsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_action_endpoints_refuse_non_supers(): void
    {
        $this->statamicUser(super: false);

        $this->assertForbidden(fn () => app(HistoryActionController::class)->run($this->actionRequest('delete_history_entry')));
        $this->assertForbidden(fn () => app(HistoryActionController::class)->bulkActions($this->actionRequest('delete_history_entry')));
    }

    /**
     * Statamic's ActionController runs any registered action handle; these
     * endpoints only accept their own.
     */
    public function test_action_endpoints_refuse_other_action_handles(): void
    {
        $this->statamicUser(super: true);

        $this->assertForbidden(fn () => app(HistoryActionController::class)->run($this->actionRequest('delete')));
    }

    public function test_the_expected_action_passes_the_guard(): void
    {
        $this->statamicUser(super: true);

        try {
            app(HistoryActionController::class)->run($this->actionRequest('delete_history_entry'));
        } catch (HttpException $e) {
            $this->assertNotSame(403, $e->getStatusCode());
        } catch (\Throwable $e) {
            // Past the guard: Statamic's own action lookup isn't booted here.
        }

        $this->addToAssertionCount(1);
    }

    public function test_cp_access_needs_a_user_with_cp_permission(): void
    {
        $this->assertFalse((new CpAccess)->allows(), 'guest');

        $this->actingAs(new \Illuminate\Auth\GenericUser(["id" => 1]));

        $this->statamicUser(super: false, cp: false);
        $this->assertFalse((new CpAccess)->allows(), 'front-end member');

        $this->statamicUser(super: false, cp: true);
        $this->assertTrue((new CpAccess)->allows(), 'editor');

        $this->statamicUser(super: true, cp: false);
        $this->assertTrue((new CpAccess)->allows(), 'super');
    }

    protected function statamicUser(bool $super, bool $cp = true): void
    {
        $user = Mockery::mock();
        $user->shouldReceive('isSuper')->andReturn($super);
        $user->shouldReceive('hasPermission')->with('access cp')->andReturn($cp);

        User::swap(Mockery::mock()->shouldReceive('current')->andReturn($user)->getMock());
    }

    protected function actionRequest(string $action): Request
    {
        return Request::create('/cp/d3-sentinel/history/actions', 'POST', [
            'action'     => $action,
            'selections' => ['abc'],
            'values'     => ['_' => ''],
        ]);
    }

    protected function assertForbidden(callable $call): void
    {
        try {
            $call();
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());

            return;
        }

        $this->fail('Expected a 403.');
    }
}
