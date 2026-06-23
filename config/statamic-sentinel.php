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
    | side-by-side. Defaults to the app timezone.
    |
    */

    'freeze' => [
        'timezone' => env('SENTINEL_FREEZE_TIMEZONE', config('app.timezone')),
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

];
