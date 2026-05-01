<?php

namespace D3Creative\Sentinel\Widgets;

use Statamic\Widgets\Widget;
use D3Creative\Sentinel\Services\AuditService;

class SentinelWidget extends Widget
{
    protected static $handle = 'sentinel';

    public function html(): string
    {
        $audit = new AuditService();
        $data  = request()->has('d3_refresh') ? $audit->refresh() : $audit->cached();

        return (string) view('statamic-sentinel::widgets.sentinel', [
            'audit' => $data,
        ]);
    }
}
