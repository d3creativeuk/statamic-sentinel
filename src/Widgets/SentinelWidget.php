<?php

namespace D3Creative\Sentinel\Widgets;

use Illuminate\Http\Exceptions\HttpResponseException;
use Statamic\Widgets\Widget;
use D3Creative\Sentinel\Services\AuditService;
use D3Creative\Sentinel\Support\ManualScan;

class SentinelWidget extends Widget
{
    protected static $handle = 'sentinel';

    public function html(): string
    {
        // The widget shows the vulnerability report, so it follows the same
        // permission as the utility rather than every dashboard user.
        if (! ManualScan::userCanView()) {
            return '';
        }

        $audit = new AuditService();

        // Scan Now / Refresh: maybe scan, then redirect without the parameter
        // so a manual F5 doesn't re-trigger it. The exception bubbles out of
        // the dashboard render pipeline as the redirect response.
        (new ManualScan)->handle(request(), $audit);

        return (string) view('statamic-sentinel::widgets.sentinel', [
            'audit' => $audit->cached(),
        ]);
    }
}
