<?php

return [
    'postmark' => ['token' => env('POSTMARK_TOKEN')],
    'ses' => ['key' => env('AWS_ACCESS_KEY_ID'), 'secret' => env('AWS_SECRET_ACCESS_KEY'), 'region' => env('AWS_DEFAULT_REGION', 'us-east-1')],
    'resend' => ['key' => env('RESEND_KEY')],
    'slack' => ['notifications' => ['bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'), 'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL')]],
    'facebook' => [
        'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v25.0'),
        'client_id' => env('FACEBOOK_CLIENT_ID', env('FACEBOOK_APP_ID')),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET', env('FACEBOOK_APP_SECRET')),
        'redirect' => env('FACEBOOK_REDIRECT_URI'),
        'login_config_id' => env('FACEBOOK_LOGIN_CONFIG_ID'),
        'scopes' => array_values(array_filter(array_map('trim', explode(',', env(
            'FACEBOOK_LOGIN_SCOPES',
            'email,public_profile'
        ))))),
        'messenger_app_id' => env('MESSENGER_APP_ID', env('FACEBOOK_CLIENT_ID', env('FACEBOOK_APP_ID'))),
        'messenger_app_secret' => env('MESSENGER_APP_SECRET', env('FACEBOOK_CLIENT_SECRET', env('FACEBOOK_APP_SECRET'))),
        'verify_token' => env('FACEBOOK_VERIFY_TOKEN'),
        'app_secret' => env('FACEBOOK_APP_SECRET'),
        'first_contact_menu' => [
            'enabled' => env('FACEBOOK_FIRST_CONTACT_MENU_ENABLED', true),
            'text' => env('FACEBOOK_FIRST_CONTACT_MENU_TEXT', 'Bạn cần Toyota Kiên Giang hỗ trợ thêm gì không ạ?'),
            'phone_enabled' => true,
            'phone_text' => 'Anh/chị có thể chia sẻ số điện thoại để Toyota Kiên Giang liên hệ tư vấn nhanh hơn.',
            'phone_button_title' => 'Chia sẻ SĐT',
            'phone_payload' => 'Khách muốn chia sẻ số điện thoại để nhân viên Toyota Kiên Giang liên hệ tư vấn. Hãy đề nghị khách nhập số điện thoại.',
            'elements' => [
                [
                    'title' => 'Các mẫu xe Toyota',
                    'subtitle' => 'Nhận thông tin mới nhất',
                    'image_url' => env('FACEBOOK_MENU_CARS_IMAGE_URL'),
                    'buttons' => [
                        ['title' => 'Tư vấn cho tôi', 'payload' => 'Khách muốn được tư vấn các mẫu xe Toyota đang bán và chọn mẫu xe phù hợp.'],
                        ['title' => 'Dự toán chi phí', 'payload' => 'Khách muốn dự toán giá lăn bánh và chi phí mua xe Toyota.'],
                    ],
                ],
                [
                    'title' => 'Khám phá xe Toyota',
                    'subtitle' => 'Giá bán, ưu đãi và màu xe',
                    'image_url' => env('FACEBOOK_MENU_DISCOVER_IMAGE_URL'),
                    'buttons' => [
                        ['title' => 'Xem bảng giá', 'payload' => 'Khách muốn xem bảng giá các mẫu xe Toyota và giá lăn bánh tại Kiên Giang.'],
                        ['title' => 'Tìm hiểu ưu đãi', 'payload' => 'Khách muốn tìm hiểu ưu đãi và khuyến mãi Toyota đang áp dụng.'],
                    ],
                ],
                [
                    'title' => 'Đăng ký tư vấn',
                    'subtitle' => 'Toyota Kiên Giang hỗ trợ trực tiếp',
                    'image_url' => env('FACEBOOK_MENU_SUPPORT_IMAGE_URL'),
                    'buttons' => [
                        ['title' => 'Đặt lịch lái thử', 'payload' => 'Khách muốn đặt lịch lái thử xe Toyota tại Toyota Kiên Giang.'],
                        ['title' => 'Nhân viên gọi lại', 'payload' => 'Khách muốn để lại số điện thoại để nhân viên Toyota Kiên Giang gọi lại tư vấn.'],
                    ],
                ],
            ],
        ],
    ],
    'zalo' => ['access_token' => env('ZALO_ACCESS_TOKEN')],
    'customer_idle_follow_up_minutes' => (int) env('CUSTOMER_IDLE_FOLLOW_UP_MINUTES', 3),
    'nim' => [
        'api_key' => env('NVIDIA_NIM_API_KEY', env('NVIDIA_API_KEY')),
        'base_url' => env('NVIDIA_NIM_BASE_URL', 'https://integrate.api.nvidia.com/v1'),
        'model' => env('NVIDIA_NIM_CHAT_MODEL', 'meta/llama-3.1-70b-instruct'),
        'timeout' => (int) env('NVIDIA_NIM_TIMEOUT', 12),
    ],
    'firebase' => [
        'enabled' => env('FIREBASE_FCM_ENABLED', false),
        'credentials' => env('FIREBASE_CREDENTIALS'),
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'client_email' => env('FIREBASE_CLIENT_EMAIL'),
        'private_key' => env('FIREBASE_PRIVATE_KEY'),
        'token_uri' => env('FIREBASE_TOKEN_URI', 'https://oauth2.googleapis.com/token'),
        'timeout' => (int) env('FIREBASE_TIMEOUT', 10),
    ],
];
