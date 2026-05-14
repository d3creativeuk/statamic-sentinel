<?php

namespace D3Creative\Sentinel\Actions;

use D3Creative\Sentinel\Services\HistoryService;
use Statamic\Actions\Action;

class DeleteHistoryEntry extends Action
{
    protected $dangerous = true;

    public static function title()
    {
        return __('Delete');
    }

    public function visibleTo($item)
    {
        return is_array($item) && (isset($item['id']) || isset($item['recorded_at']));
    }

    public function authorize($user, $item)
    {
        return $user?->isSuper() === true;
    }

    public function confirmationText()
    {
        return 'Are you sure you want to delete this history entry?|Are you sure you want to delete these :count history entries?';
    }

    public function run($items, $values)
    {
        $service = app(HistoryService::class);

        $items->each(function ($item) use ($service) {
            $service->delete($item['id'] ?? $item['recorded_at'] ?? '');
        });

        return trans_choice('History entry deleted|:count history entries deleted', $items->count());
    }
}
