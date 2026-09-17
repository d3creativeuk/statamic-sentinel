<?php

namespace D3Creative\Sentinel\Http\Controllers;

use D3Creative\Sentinel\Services\SentMailService;
use D3Creative\Sentinel\Http\Controllers\Concerns\GuardsSentinelActions;
use Statamic\Http\Controllers\CP\ActionController;

class SentMailActionController extends ActionController
{
    use GuardsSentinelActions;

    const SENTINEL_ACTION = 'delete_sent_email';

    protected function getSelectedItems($items, $context)
    {
        $service = app(SentMailService::class);

        return $items
            ->map(fn ($id) => $service->find($id))
            ->filter()
            ->values();
    }
}
