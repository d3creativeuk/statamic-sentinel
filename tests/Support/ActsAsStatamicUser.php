<?php

namespace D3Creative\Sentinel\Tests\Support;

use Mockery;
use Statamic\Facades\User;

/**
 * Signs in the way a database-user site does: the auth guard holds the
 * host's Eloquent model (no isSuper(), id() or email() method), and only
 * Statamic's User::current() says whether it's a super. Code that asks
 * auth()->user() instead throws here, as it does on those sites.
 */
trait ActsAsStatamicUser
{
    /**
     * @param array<int, string> $permissions granted to a non-super
     */
    protected function actingAsStatamicUser(bool $super, string $email = 'super@example.test', ?string $model = null, array $permissions = []): void
    {
        $model ??= HostEloquentUser::class;
        $this->actingAs((new $model)->forceFill(['id' => 1, 'email' => $email, 'super' => $super]));

        $user = Mockery::mock();
        $user->shouldReceive('isSuper')->andReturn($super);
        $user->shouldReceive('hasPermission')->andReturnUsing(fn ($permission) => $super || in_array($permission, $permissions, true));
        $user->shouldReceive('id')->andReturn('user-1');
        $user->shouldReceive('email')->andReturn($email);

        User::swap(Mockery::mock()->shouldReceive('current')->andReturn($user)->getMock());
    }
}
