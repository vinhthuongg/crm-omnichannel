<?php

return [
    'vector' => [
        'enabled' => env('VECTOR_SEARCH_ENABLED', true),
        'provider' => env('VECTOR_SEARCH_PROVIDER', 'local'),
        'dimensions' => (int) env('VECTOR_SEARCH_DIMENSIONS', 384),
        'top_k' => (int) env('VECTOR_SEARCH_TOP_K', 50),
        'min_score' => (float) env('VECTOR_SEARCH_MIN_SCORE', 0.08),
        'customer_message_limit' => (int) env('VECTOR_SEARCH_CUSTOMER_MESSAGE_LIMIT', 40),
    ],
];
