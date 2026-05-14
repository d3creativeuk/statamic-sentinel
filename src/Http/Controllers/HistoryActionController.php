<?php

namespace D3Creative\Sentinel\Http\Controllers;

use D3Creative\Sentinel\Services\HistoryService;
use Statamic\Http\Controllers\CP\ActionController;

class HistoryActionController extends ActionController
{
    protected function getSelectedItems($items, $context)
    {
        $all = collect(app(HistoryService::class)->all());

        return $items
            ->map(fn ($key) => $all->first(fn ($e) => ($e['id'] ?? null) === $key || ($e['recorded_at'] ?? null) === $key))
            ->filter()
            ->values();
    }
}
