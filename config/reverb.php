<?php

return [
    'apps' => [
        'provider' => 'config',
        'apps' => [[
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => config('broadcasting.connections.reverb.options'),
            'allowed_origins' => [env('REVERB_ALLOWED_ORIGIN') ?: parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST)],
            'ping_interval' => 60,
            'activity_timeout' => 30,
            'max_message_size' => 2048,
            'accept_client_events_from' => 'none',
        ]],
    ],
];
