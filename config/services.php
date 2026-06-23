<?php

return [
    'postmark' => ['token' => env('POSTMARK_TOKEN')],
    'ses' => ['key' => env('AWS_ACCESS_KEY_ID'), 'secret' => env('AWS_SECRET_ACCESS_KEY'), 'region' => env('AWS_DEFAULT_REGION', 'us-east-1')],
    'resend' => ['key' => env('RESEND_KEY')],
    'slack' => ['notifications' => ['bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'), 'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL')]],
    'facebook' => [
        'page_access_token' => env('FACEBOOK_PAGE_ACCESS_TOKEN'),
        'verify_token' => env('FACEBOOK_VERIFY_TOKEN'),
        'app_secret' => env('FACEBOOK_APP_SECRET'),
    ],
    'zalo' => ['access_token' => env('ZALO_ACCESS_TOKEN')],
];
