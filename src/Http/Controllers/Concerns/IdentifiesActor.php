<?php

namespace D3Creative\Sentinel\Http\Controllers\Concerns;

trait IdentifiesActor
{
    /**
     * Statamic user IDs are strings - take whatever the auth user exposes
     * and stringify it. Falls back to email when the id() helper is absent
     * on older Statamic versions.
     */
    protected function actorId(): ?string
    {
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        if (method_exists($user, 'id')) {
            $id = $user->id();
            if ($id !== null && $id !== '') {
                return (string) $id;
            }
        }

        return $user->email ?? null;
    }
}
