<?php

namespace Modules\Facebook\Services;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Arr;

class FacebookSubscriptionClient
{
    /** Khởi tạo client quản lý app webhook subscription. */
    public function __construct(private readonly Http $http, private readonly FacebookAppConfig $config) {}

    /** Liệt kê các webhook subscription hiện có của Facebook App. */
    public function list(): array
    {
        $response = $this->http->connectTimeout(5)->timeout(15)->get($this->url(), ['access_token' => $this->config->loginAccessToken()]);
        $response->throw();
        return (array) Arr::get($response->json(), 'data', []);
    }

    /** Tạo hoặc cập nhật webhook subscription với danh sách field yêu cầu. */
    public function subscribe(string $fields): array
    {
        $response = $this->http->connectTimeout(5)->timeout(15)->asForm()->post($this->url(), ['object' => 'page',
            'callback_url' => $this->config->callbackUrl(), 'verify_token' => $this->config->verifyToken(), 'fields' => $fields,
            'include_values' => 'true', 'access_token' => $this->config->loginAccessToken()]);
        $response->throw();
        return $response->json();
    }

    /** Tạo endpoint subscription của App đang cấu hình. */
    private function url(): string { return $this->config->graphUrl('/'.$this->config->loginId().'/subscriptions'); }
}
