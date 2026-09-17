<?php

namespace D3Creative\Sentinel\Support;

/**
 * Whether the current request's user may use the Control Panel. Sentinel's
 * middleware runs on the whole `statamic.cp` group, which also wraps the
 * login pages, and a signed-in front-end member isn't necessarily a CP user.
 * Resolved from the container so tests can swap it.
 */
class CpAccess
{
    public function allows(): bool
    {
        try {
            if (! auth()->check()) {
                return false;
            }

            $user = \Statamic\Facades\User::current();

            return $user !== null && ($user->isSuper() || $user->hasPermission('access cp'));
        } catch (\Throwable $e) {
            return false;
        }
    }
}
