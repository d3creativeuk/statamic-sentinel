<?php

namespace D3Creative\Sentinel\Http\Controllers;

use D3Creative\Sentinel\Services\ContentFreezeService;
use Statamic\Http\Controllers\CP\ActionController;

class FreezeHistoryActionController extends ActionController
{
    protected function getSelectedItems($items, $context)
    {
        $all = collect(app(ContentFreezeService::class)->history());

        return $items
            ->map(fn ($id) => $all->first(fn ($e) => ($e['id'] ?? null) === $id))
            ->filter()
            ->values();
    }
}
