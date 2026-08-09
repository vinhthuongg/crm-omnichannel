<?php

return [
    'enabled' => env('CHATBOT_ENABLED', false),
    'base_url' => rtrim((string) env('CHATBOT_BASE_URL', 'http://127.0.0.1:3000/api/v1'), '/'),
    'api_key' => env('CHATBOT_API_KEY'),
    'connect_timeout' => (int) env('CHATBOT_CONNECT_TIMEOUT', 5),
    'stream_timeout' => (int) env('CHATBOT_STREAM_TIMEOUT', 120),
    'lock_seconds' => (int) env('CHATBOT_LOCK_SECONDS', 150),
    'queue' => env('CHATBOT_QUEUE', 'default'),
    'max_inbound_images' => (int) env('CHATBOT_MAX_INBOUND_IMAGES', 5),
    'quick_replies_enabled' => env('CHATBOT_QUICK_REPLIES_ENABLED', true),
    'default_quick_replies' => [
        ['content_type' => 'text', 'title' => 'Xem bảng giá', 'payload' => 'Khách muốn xem bảng giá và giá lăn bánh của mẫu xe đang được tư vấn.'],
        ['content_type' => 'text', 'title' => 'Tư vấn trả góp', 'payload' => 'Khách muốn được tư vấn phương án trả góp cho mẫu xe đang quan tâm.'],
        ['content_type' => 'text', 'title' => 'Đặt lịch lái thử', 'payload' => 'Khách muốn đặt lịch lái thử mẫu xe đang được tư vấn.'],
    ],
];
