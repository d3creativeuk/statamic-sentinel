<?php

namespace D3Creative\Sentinel\Support;

/**
 * The signed-in CP user as a Statamic user. With database users the auth
 * guard returns the host's Eloquent model (App\Models\User), which has no
 * isSuper(), id() or email(): calling isSuper() on it throws, or reaches a
 * query scope of that name and returns a truthy Builder. Statamic's own CP
 * code always goes through User::current(), which wraps the model.
 */
class CurrentUser
{
    /**
     * @return \Statamic\Contracts\Auth\User|null
     */
    public static function get()
    {
        try {
            return \Statamic\Facades\User::current();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function isSuper(): bool
    {
        try {
            return static::get()?->isSuper() === true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Supers have every permission, as in Statamic.
     */
    public static function hasPermission(string $permission): bool
    {
        try {
            return static::isSuper() || static::get()?->hasPermission($permission) === true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function id(): ?string
    {
        try {
            $user = static::get();

            if (! $user) {
                return null;
            }

            $id = $user->id();

            if ($id !== null && $id !== '') {
                return (string) $id;
            }

            return $user->email() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function email(): string
    {
        try {
            return (string) (static::get()?->email() ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }
}
