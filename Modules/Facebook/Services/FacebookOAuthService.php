<?php

namespace Modules\Facebook\Services;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\URL;
use Modules\Facebook\DTO\FacebookPageData;
use Modules\Facebook\DTO\FacebookUserData;
use RuntimeException;

class FacebookOAuthService
{
    public function __construct(
        private readonly Http $http,
        private readonly FacebookTokenValidationService $tokens,
    ) {
    }

    public function authorizationUrl(string $state): string
    {
        $this->ensureConfigured();

        $params = [
            'client_id' => $this->appId(),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'response_type' => 'code',
        ];
        $configId = $this->loginConfigId();

        if ($configId !== '') {
            $params['config_id'] = $configId;
        }

        $scopes = $configId === '' ? $this->scopes() : [];

        if ($scopes !== []) {
            $params['scope'] = implode(',', $scopes);
        }

        return 'https://www.facebook.com/'.$this->graphVersion().'/dialog/oauth?'.http_build_query($params);
    }

    public function userFromCode(string $code): FacebookUserData
    {
        $token = $this->exchangeCode($code);
        $accessToken = (string) Arr::get($token, 'access_token');

        if ($accessToken === '') {
            throw new RuntimeException('Facebook did not return an access token.');
        }

        $this->tokens->validateUserToken($accessToken);

        $profile = $this->graphGet('/me', $accessToken, [
            'fields' => 'id,name,email',
        ]);

        return new FacebookUserData(
            (string) Arr::get($profile, 'id'),
            (string) Arr::get($profile, 'name', 'Facebook User'),
            Arr::get($profile, 'email'),
            $accessToken,
            Arr::get($token, 'refresh_token'),
        );
    }

    /**
     * @return array<int, FacebookPageData>
     */
    public function pages(string $userAccessToken): array
    {
        $this->tokens->validateUserToken($userAccessToken);

        $pages = [];
        $url = $this->graphUrl('/me/accounts');
        $params = [
            'fields' => 'id,name,access_token,picture{url}',
            'limit' => 100,
            'access_token' => $userAccessToken,
        ];

        do {
            $params['access_token'] = $userAccessToken;
            $response = $this->http
                ->connectTimeout(5)
                ->timeout(15)
                ->get($url, $params);
            $response->throw();
            $payload = $response->json();

            foreach ((array) Arr::get($payload, 'data', []) as $page) {
                $pageToken = (string) Arr::get($page, 'access_token');

                if ($pageToken === '') {
                    continue;
                }

                $this->tokens->validatePageToken($pageToken);

                $pages[] = new FacebookPageData(
                    (string) Arr::get($page, 'id'),
                    (string) Arr::get($page, 'name'),
                    $pageToken,
                    Arr::get($page, 'picture.data.url'),
                );
            }

            $url = Arr::get($payload, 'paging.next');
            $params = [];
        } while ($url);

        return $pages;
    }

    public function pagePicture(string $pageId, string $pageAccessToken): ?string
    {
        $this->tokens->validatePageToken($pageAccessToken);

        $payload = $this->graphGet("/{$pageId}", $pageAccessToken, [
            'fields' => 'picture{url}',
        ]);

        return Arr::get($payload, 'picture.data.url');
    }

    public function subscribePage(string $pageId, string $pageAccessToken): void
    {
        $this->tokens->validatePageToken($pageAccessToken);

        $response = $this->http
            ->connectTimeout(5)
            ->timeout(15)
            ->asForm()
            ->post($this->graphUrl("/{$pageId}/subscribed_apps"), [
                'subscribed_fields' => 'messages,messaging_postbacks,message_deliveries,message_reads',
                'access_token' => $pageAccessToken,
            ]);

        $response->throw();
    }

    private function exchangeCode(string $code): array
    {
        $this->ensureConfigured();

        $response = $this->http
            ->connectTimeout(5)
            ->timeout(15)
            ->get($this->graphUrl('/oauth/access_token'), [
                'client_id' => $this->appId(),
                'client_secret' => $this->appSecret(),
                'redirect_uri' => $this->redirectUri(),
                'code' => $code,
            ]);

        $response->throw();

        return $response->json();
    }

    private function graphGet(string $path, string $accessToken, array $params = []): array
    {
        $response = $this->http
            ->connectTimeout(5)
            ->timeout(15)
            ->get($this->graphUrl($path), array_merge($params, [
                'access_token' => $accessToken,
            ]));

        $response->throw();

        return $response->json();
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.$this->graphVersion().$path;
    }

    private function graphVersion(): string
    {
        return (string) config('services.facebook.graph_version', 'v25.0');
    }

    private function appId(): string
    {
        return (string) config('services.facebook.client_id');
    }

    private function appSecret(): string
    {
        return (string) config('services.facebook.client_secret');
    }

    private function redirectUri(): string
    {
        return (string) (config('services.facebook.redirect') ?: URL::route('facebook.callback'));
    }

    private function loginConfigId(): string
    {
        return (string) config('services.facebook.login_config_id', '');
    }

    private function scopes(): array
    {
        $scopes = config('services.facebook.scopes', ['email']);

        return is_array($scopes) ? $scopes : ['email'];
    }

    private function ensureConfigured(): void
    {
        if ($this->appId() === '') {
            throw new RuntimeException('FACEBOOK_CLIENT_ID is missing in .env.');
        }

        if ($this->appSecret() === '') {
            throw new RuntimeException('FACEBOOK_CLIENT_SECRET or FACEBOOK_APP_SECRET is missing in .env.');
        }

        if ($this->redirectUri() === '') {
            throw new RuntimeException('FACEBOOK_REDIRECT_URI is missing in .env.');
        }
    }
}
