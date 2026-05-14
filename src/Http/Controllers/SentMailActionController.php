<?php

namespace D3Creative\Sentinel\Http\Controllers;

use D3Creative\Sentinel\Services\SentMailService;
use Statamic\Http\Controllers\CP\ActionController;

class SentMailActionController extends ActionController
{
    protected function getSelectedItems($items, $context)
    {
        $service = app(SentMailService::class);

        return $items
            ->map(fn ($id) => $service->find($id))
            ->filter()
            ->values();
    }
}
