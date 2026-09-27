<?php

declare(strict_types=1);

return [

    // Where laya-serve is running.
    'url' => env('LAYA_URL', 'http://localhost:8000'),

    // Only needed when laya-serve is started with an API key.
    'api_key' => env('LAYA_API_KEY'),

    // A cache store (e.g. "redis") to remember predictions in; null disables caching.
    // Clear it, or set a TTL, when you upgrade laya-serve's checkpoints.
    'cache' => [
        'store' => env('LAYA_CACHE_STORE'),
        'ttl' => env('LAYA_CACHE_TTL'), // seconds; null keeps entries as long as the store does
    ],

];
