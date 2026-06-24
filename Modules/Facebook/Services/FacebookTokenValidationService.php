<?php

namespace Modules\Facebook\Services;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Arr;
use RuntimeException;

class FacebookTokenValidationService
{
    public function __construct(private readonly Http $http)
    {
    }

    public function validateUserToken(string $accessToken): array
    {
        return $this->validateToken(
            $accessToken,
            'user',
            $this->loginAppId(),
            $this->loginAppAccessToken(),
        );
    }

    public function validatePageToken(string $accessToken): array
    {
        return $this->validateToken(
            $accessToken,
            'page',
            $this->messengerAppId(),
            $this->messengerAppAccessToken(),
        );
    }

    public function validateMessengerUserToken(string $accessToken): array
    {
        return $this->validateToken(
            $accessToken,
            'messenger user',
            $this->messengerAppId(),
            $this->messengerAppAccessToken(),
        );
    }

    public function debugToken(string $accessToken, ?string $appAccessToken = null): array
    {
        $response = $this->http
            ->connectTimeout(5)
            ->timeout(12)
            ->get($this->graphUrl('/debug_token'), [
                'input_token' => $accessToken,
                'access_token' => $appAccessToken ?: $this->loginAppAccessToken(),
            ]);

        $response->throw();

        return (array) Arr::get($response->json(), 'data', []);
    }

    public function ensurePageBelongsToMessengerApp(?string $messengerAppId): void
    {
        $expectedAppId = $this->messengerAppId();

        if ((string) $messengerAppId !== $expectedAppId) {
            throw new \InvalidArgumentException('Page belongs to different Messenger App');
        }
    }

    private function validateToken(string $accessToken, string $label, string $expectedAppId, string $appAccessToken): array
    {
        if ($accessToken === '') {
            throw new RuntimeException("Facebook {$label} access token is empty.");
        }

        if ($expectedAppId === '') {
            throw new RuntimeException("Expected Facebook {$label} app id is missing in .env.");
        }

        $data = $this->debugToken($accessToken, $appAccessToken);

        if (! (bool) Arr::get($data, 'is_valid')) {
            throw new RuntimeException("Invalid Facebook {$label} access token.");
        }

        $tokenAppId = (string) Arr::get($data, 'app_id');

        if ($tokenAppId !== $expectedAppId) {
            throw new RuntimeException("Facebook {$label} token app_id mismatch. Expected {$expectedAppId}, got {$tokenAppId}.");
        }

        return $data;
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.$this->graphVersion().$path;
    }

    private function graphVersion(): string
    {
        return (string) config('services.facebook.graph_version', 'v25.0');
    }

    private function loginAppAccessToken(): string
    {
        if ($this->loginAppId() === '' || $this->loginAppSecret() === '') {
            throw new RuntimeException('FACEBOOK_CLIENT_ID or FACEBOOK_CLIENT_SECRET is missing in .env.');
        }

        return $this->loginAppId().'|'.$this->loginAppSecret();
    }

    private function messengerAppAccessToken(): string
    {
        if ($this->messengerAppId() === '' || $this->messengerAppSecret() === '') {
            throw new RuntimeException('MESSENGER_APP_ID or MESSENGER_APP_SECRET is missing in .env.');
        }

        return $this->messengerAppId().'|'.$this->messengerAppSecret();
    }

    private function loginAppId(): string
    {
        return (string) config('services.facebook.client_id');
    }

    private function loginAppSecret(): string
    {
        return (string) config('services.facebook.client_secret');
    }

    private function messengerAppId(): string
    {
        return (string) config('services.facebook.messenger_app_id');
    }

    private function messengerAppSecret(): string
    {
        return (string) config('services.facebook.messenger_app_secret');
    }
}
