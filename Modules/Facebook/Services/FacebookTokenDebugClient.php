<?php

namespace Modules\Facebook\Services;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Arr;

class FacebookTokenDebugClient
{
    /** Khởi tạo client kiểm tra token bằng cấu hình Facebook App. */
    public function __construct(private readonly Http $http, private readonly FacebookAppConfig $config) {}

    /** Gọi debug_token để lấy metadata và trạng thái hợp lệ của access token. */
    public function debug(string $token, string $appToken): array
    {
        $response = $this->http->connectTimeout(5)->timeout(12)->get($this->config->graphUrl('/debug_token'),
            ['input_token' => $token, 'access_token' => $appToken]);
        $response->throw();
        return (array) Arr::get($response->json(), 'data', []);
    }
}
