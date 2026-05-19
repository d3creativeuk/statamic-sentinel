<?php

namespace D3Creative\Sentinel\Widgets;

use Illuminate\Http\Exceptions\HttpResponseException;
use Statamic\Widgets\Widget;
use D3Creative\Sentinel\Services\AuditService;

class SentinelWidget extends Widget
{
    protected static $handle = 'sentinel';

    public function html(): string
    {
        $audit = new AuditService();

        // Run the refresh then redirect to the same URL with `d3_refresh`
        // stripped, so a manual F5 doesn't re-trigger the audit. The exception
        // bubbles out of the dashboard render pipeline; Laravel turns it back
        // into the redirect response.
        if (request()->has('d3_refresh')) {
            $audit->refresh();
            throw new HttpResponseException(
                redirect()->to(request()->fullUrlWithoutQuery('d3_refresh'))
            );
        }

        return (string) view('statamic-sentinel::widgets.sentinel', [
            'audit' => $audit->cached(),
        ]);
    }
}
