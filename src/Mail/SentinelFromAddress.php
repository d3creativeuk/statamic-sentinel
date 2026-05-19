<?php

namespace D3Creative\Sentinel\Mail;

use Illuminate\Support\Facades\Log;

/**
 * Picks a from() address for Sentinel-sent mail. Hosts that have set
 * MAIL_FROM_ADDRESS get their configured value; hosts that haven't get a
 * synthesised `noreply@{app-host}` so the addon never throws a "From not
 * configured" exception at send time and break the CP.
 */
trait SentinelFromAddress
{
    protected function applySentinelFrom(): void
    {
        if ($address = config('mail.from.address')) {
            $this->from($address, config('mail.from.name'));
            return;
        }

        $host = parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost';
        $fallback = 'noreply@' . $host;

        Log::warning('Sentinel mail: MAIL_FROM_ADDRESS is not configured; falling back to ' . $fallback);

        $this->from($fallback, config('app.name') ?: 'Sentinel');
    }
}
