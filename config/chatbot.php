<?php

return [
    'enabled' => env('CHATBOT_ENABLED', false),
    'base_url' => rtrim((string) env('CHATBOT_BASE_URL', 'http://127.0.0.1:3000/api/v1'), '/'),
    'api_key' => env('CHATBOT_API_KEY'),
    'connect_timeout' => (int) env('CHATBOT_CONNECT_TIMEOUT', 5),
    'stream_timeout' => (int) env('CHATBOT_STREAM_TIMEOUT', 120),
    'lock_seconds' => (int) env('CHATBOT_LOCK_SECONDS', 150),
    'queue' => env('CHATBOT_QUEUE', 'default'),
];
