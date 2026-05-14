<?php

namespace D3Creative\Sentinel\Actions;

use D3Creative\Sentinel\Services\ContentFreezeService;
use Statamic\Actions\Action;

class DeleteFreezeHistory extends Action
{
    protected $dangerous = true;

    public static function title()
    {
        return __('Delete');
    }

    public function visibleTo($item)
    {
        return is_array($item) && isset($item['id']);
    }

    public function authorize($user, $item)
    {
        return $user?->isSuper() === true;
    }

    public function confirmationText()
    {
        return 'Delete this past freeze record?|Delete these :count past freeze records?';
    }

    public function run($items, $values)
    {
        $service = app(ContentFreezeService::class);

        $items->each(fn ($item) => $service->deleteHistory($item['id']));

        return trans_choice('Freeze record deleted|:count freeze records deleted', $items->count());
    }
}
