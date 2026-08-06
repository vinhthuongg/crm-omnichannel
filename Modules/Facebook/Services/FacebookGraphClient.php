<?php

namespace Modules\Facebook\Services;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;

class FacebookGraphClient
{
    /** Khởi tạo Graph client bằng HTTP factory của Laravel. */
    public function __construct(private readonly Http $http)
    {
    }

    /** Gửi GET request đến Graph API và trả về payload JSON đã xác thực. */
    public function get(string $url, array $params = [], ?string $token = null): array
    {
        if ($token) $params['access_token'] = $token;
        Log::debug('Facebook graph sync request', ['url' => $this->safeUrl($url),
            'has_access_token' => filled($params['access_token'] ?? null), 'fields' => $params['fields'] ?? null, 'limit' => $params['limit'] ?? null]);
        $response = $this->http->connectTimeout(5)->timeout(15)->get($url, $params);
        $response->throw();
        return $response->json();
    }

    /** Tạo URL Graph API đầy đủ từ endpoint tương đối. */
    public function url(string $path): string
    {
        return 'https://graph.facebook.com/'.config('services.facebook.graph_version', 'v25.0').$path;
    }

    /** Loại access token khỏi URL trước khi ghi log hoặc hiển thị lỗi. */
    public function safeUrl(string $url): string
    {
        return preg_replace('/([?&]access_token=)[^&]+/', '$1[redacted]', $url) ?: $url;
    }
}
