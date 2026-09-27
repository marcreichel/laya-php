<?php

declare(strict_types=1);

return [

    // Where laya-serve is running.
    'url' => env('LAYA_URL', 'http://localhost:8000'),

    // Only needed when laya-serve is started with an API key.
    'api_key' => env('LAYA_API_KEY'),

];
