<?php

namespace Modules\Zalo\Services;

use Illuminate\Http\Client\Factory as Http;

class ZaloOaService
{
    public function __construct(private readonly Http $http)
    {
    }

    public function sendText(string $userId, string $message): array
    {
        $response = $this->http->withHeaders(['access_token' => (string) config('services.zalo.access_token')])->post('https://openapi.zalo.me/v3.0/oa/message/cs', ['recipient' => ['user_id' => $userId], 'message' => ['text' => $message]]);
        $response->throw();
        return $response->json();
    }
}