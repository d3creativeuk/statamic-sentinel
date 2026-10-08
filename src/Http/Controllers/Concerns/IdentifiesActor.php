<?php

namespace D3Creative\Sentinel\Http\Controllers\Concerns;

use D3Creative\Sentinel\Support\CurrentUser;

trait IdentifiesActor
{
    /**
     * The Statamic user's id as a string (email if it has none). The auth
     * guard's user is the host's Eloquent model on database-user sites, so
     * go through the Statamic user rather than auth()->user().
     */
    protected function actorId(): ?string
    {
        return CurrentUser::id();
    }
}
