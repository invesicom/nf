<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Google AdSense Configuration
    |--------------------------------------------------------------------------
    |
    | Configure Google AdSense integration. Set GOOGLE_ADSENSE in your .env
    | file to your full client ID (e.g., "ca-pub-9065219955840058") to enable ads.
    |
    */
    'adsense' => [
        'publisher_id' => env('GOOGLE_ADSENSE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Analytics Configuration
    |--------------------------------------------------------------------------
    |
    | Google Analytics tracking ID. Currently hardcoded in templates but
    | could be moved here for consistency.
    |
    */
    'analytics' => [
        'tracking_id' => env('GOOGLE_ANALYTICS_ID', 'G-BYWNNLXEYV'),
    ],
];

