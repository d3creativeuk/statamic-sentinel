<?php

namespace D3Creative\Sentinel\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Statamic's ActionController runs whichever registered action handle the
 * request names and only checks that action's own authorize(), which
 * defaults to true. These endpoints only sit behind CP authentication, so
 * restrict them to supers and to the one delete action each is for.
 */
trait GuardsSentinelActions
{
    public function run(Request $request)
    {
        $this->guardSentinelAction($request);

        abort_unless($request->input('action') === static::SENTINEL_ACTION, 403);

        return parent::run($request);
    }

    public function bulkActions(Request $request)
    {
        $this->guardSentinelAction($request);

        return parent::bulkActions($request);
    }

    protected function guardSentinelAction(Request $request): void
    {
        abort_unless(\Statamic\Facades\User::current()?->isSuper() === true, 403);
    }
}
