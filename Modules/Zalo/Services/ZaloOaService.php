<?php

namespace Modules\Zalo\Services;

use Illuminate\Http\Client\Factory as Http;

class ZaloOaService
{
    /** Nhận HTTP client để gửi tin nhắn qua Zalo Official Account API. */
    public function __construct(private readonly Http $http)
    {
    }

    /** Gửi tin text đến Zalo user ID và trả payload JSON của Zalo OA. */
    public function sendText(string $userId, string $message): array
    {
        $response = $this->http->withHeaders(['access_token' => (string) config('services.zalo.access_token')])->post('https://openapi.zalo.me/v3.0/oa/message/cs', ['recipient' => ['user_id' => $userId], 'message' => ['text' => $message]]);
        $response->throw();
        return $response->json();
    }
}
