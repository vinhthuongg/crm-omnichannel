<?php

return [
    'enabled' => env('CHATBOT_ENABLED', true),
    'reply_delay_seconds' => env('CHATBOT_REPLY_DELAY_SECONDS', 0),
    'nim' => [
        'base_url' => env('NVIDIA_NIM_BASE_URL', 'https://integrate.api.nvidia.com/v1'),
        'api_key' => env('NVIDIA_NIM_API_KEY'),
        'chat_model' => env('NVIDIA_NIM_CHAT_MODEL', 'meta/llama-3.1-70b-instruct'),
        'embedding_model' => env('NVIDIA_NIM_EMBEDDING_MODEL', 'nvidia/nv-embedqa-e5-v5'),
    ],
    'vector' => [
        'index_path' => storage_path('app/chatbot/vector-index.json'),
        'fallback_dimensions' => 384,
        'top_k' => 6,
    ],
];
