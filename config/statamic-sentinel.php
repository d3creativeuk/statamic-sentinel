<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Developer attribution
    |--------------------------------------------------------------------------
    |
    | Optional branding shown in the CP widget, utility footer, and report
    | emails. When `name` is empty, Sentinel renders unbranded. When `name`
    | is set without a `url`, the name renders as plain text.
    |
    */

    'developer' => [
        'name'  => env('SENTINEL_DEV_NAME'),
        'url'   => env('SENTINEL_DEV_URL'),
        'email' => env('SENTINEL_DEV_EMAIL'),
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
