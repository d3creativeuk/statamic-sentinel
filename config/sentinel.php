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

];
