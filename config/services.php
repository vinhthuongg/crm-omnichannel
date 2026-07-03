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
    ],
    'zalo' => ['access_token' => env('ZALO_ACCESS_TOKEN')],
    'botpress' => [
        'enabled' => env('BOTPRESS_ENABLED', false),
        'webhook_id' => env('BOTPRESS_WEBHOOK_ID'),
        'base_url' => env('BOTPRESS_BASE_URL', 'https://chat.botpress.cloud'),
        'encryption_key' => env('BOTPRESS_ENCRYPTION_KEY'),
        'response_poll_attempts' => (int) env('BOTPRESS_RESPONSE_POLL_ATTEMPTS', 8),
        'response_poll_delay_ms' => (int) env('BOTPRESS_RESPONSE_POLL_DELAY_MS', 700),
    ],
    'nim' => [
        'api_key' => env('NVIDIA_NIM_API_KEY', env('NVIDIA_API_KEY')),
        'base_url' => env('NVIDIA_NIM_BASE_URL', 'https://integrate.api.nvidia.com/v1'),
        'model' => env('NVIDIA_NIM_CHAT_MODEL', 'meta/llama-3.1-70b-instruct'),
        'timeout' => (int) env('NVIDIA_NIM_TIMEOUT', 12),
    ],
];
