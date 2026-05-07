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

];
