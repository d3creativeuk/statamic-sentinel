<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Branding / developer attribution
    |--------------------------------------------------------------------------
    |
    | Out of the box Sentinel is branded for D3 Creative - the CP widget,
    | utility footer, and report emails attribute to D3 Creative, link to the
    | managed-maintenance service, and add a "Need help with your website?"
    | mailto button. No configuration is required for this default.
    |
    | Agencies installing Sentinel on a client site have two options:
    |
    |  - White-label: override any of the SENTINEL_DEV_* vars below to swap in
    |    your own name, link, and contact email. A `name` set without a `url`
    |    renders as plain text.
    |
    |  - Remove branding: set SENTINEL_BRANDING=false to render Sentinel fully
    |    unbranded ("Sentinel for Statamic", no link, no CTA).
    |
    */

    'branding' => [
        'enabled' => env('SENTINEL_BRANDING', true),
    ],

    'developer' => [
        'name'  => env('SENTINEL_DEV_NAME', 'D3 Creative'),
        'url'   => env('SENTINEL_DEV_URL', 'https://d3creative.uk/services/statamic-maintenance'),
        'email' => env('SENTINEL_DEV_EMAIL', 'support@d3creative.uk'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Freeze
    |--------------------------------------------------------------------------
    |
    | Display timezone for freeze times. Admins enter notify_at / freeze_at
    | in this timezone, and CP / email times are rendered in it. When this
    | differs from the server (Laravel app) timezone, both are shown
    | side-by-side.
    |
    | Leave SENTINEL_FREEZE_TIMEZONE unset (null) to use the app timezone and
    | render times WITHOUT timezone letters (e.g. "4 Jul 2026, 08:00"). Set it
    | to an explicit zone to render the timezone abbreviation too (e.g. "BST")
    | and to enable the side-by-side dual-time display when it differs from the
    | server timezone.
    |
    */

    'freeze' => [
        'timezone' => env('SENTINEL_FREEZE_TIMEZONE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Vendor security check
    |--------------------------------------------------------------------------
    |
    | When enabled, Sentinel asks the Statamic marketplace API whether any
    | release newer than the installed version is flagged as a security
    | release by the vendor - the same signal the built-in CP updater uses.
    | This catches vendor-published security patches before they appear in
    | the public OSV / GHSA advisory feeds.
    |
    | Disable for air-gapped installs or test environments that should not
    | reach out to statamic.com during a scan.
    |
    */

    'vendor_security_check' => env('SENTINEL_VENDOR_SECURITY_CHECK', true),

    /*
    |--------------------------------------------------------------------------
    | User activity ("who's online")
    |--------------------------------------------------------------------------
    |
    | Sentinel records each CP user's last-active time (throttled to ~1 write
    | per minute per user) so the utility's Users tab can show who's recently
    | online, alongside their last login. Statamic has no built-in "online
    | users" concept, so this is tracked by a lightweight CP middleware.
    |
    | Set SENTINEL_TRACK_ACTIVITY=false to disable the tracking entirely (the
    | Users tab then shows last-login only). SENTINEL_ONLINE_WINDOW is how many
    | minutes of inactivity still count as "online" - since activity only
    | advances on a CP request, this is "active in the last N minutes".
    |
    */

    'users' => [
        'track_activity' => env('SENTINEL_TRACK_ACTIVITY', true),
        'online_window'  => (int) env('SENTINEL_ONLINE_WINDOW', 5),
    ],

];
