<?php

namespace D3Creative\Sentinel\Widgets;

use Statamic\Widgets\Widget;
use D3Creative\Sentinel\Services\AuditService;

class SentinelWidget extends Widget
{
    protected static $handle = 'sentinel';

    public function html(): string
    {
        $audit   = new AuditService();
        $refresh = request()->has('d3_refresh');

        $data = $refresh ? $audit->refresh() : $audit->run();

        return (string) view('d3creative-sentinel::widgets.sentinel', [
            'statamic'   => $data['statamic'],
            'laravel'    => $data['laravel'],
            'php'        => $data['php'],
            'composer'   => $data['composer'],
            'npm'        => $data['npm'],
            'audited_at' => $data['audited_at'],
        ]);
    }
}
