<?php

namespace D3Creative\Sentinel\Actions;

use D3Creative\Sentinel\Services\SentMailService;
use Statamic\Actions\Action;

class DeleteSentEmail extends Action
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
        return 'Delete this sent record? The HTML preview will also be removed.|Delete these :count sent records? Their HTML previews will also be removed.';
    }

    public function run($items, $values)
    {
        $service = app(SentMailService::class);

        $items->each(fn ($item) => $service->delete($item['id']));

        return trans_choice('Sent record deleted|:count sent records deleted', $items->count());
    }
}
