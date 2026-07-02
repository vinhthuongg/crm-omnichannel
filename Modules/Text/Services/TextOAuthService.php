<?php

namespace Modules\Text\Services;

use Illuminate\Support\Carbon;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\File;
use RuntimeException;

class TextOAuthService
{
    public function __construct(private readonly Http $http)
    {
    }

    public function authorizationUrl(string $state): string
    {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'prompt' => 'consent',
        ], '', '&', PHP_QUERY_RFC3986);

        return 'https://accounts.livechat.com/?'.$query;
    }

    public function exchangeCode(string $code): array
    {
        $response = $this->http
            ->asForm()
            ->acceptJson()
            ->post('https://accounts.livechat.com/v2/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'redirect_uri' => $this->redirectUri(),
            ]);

        $response->throw();

        return $response->json();
    }

    public function refreshAgentToken(): array
    {
        $refreshToken = (string) config('services.text.agent_refresh_token', '');

        if ($refreshToken === '') {
            throw new RuntimeException('TEXT_AGENT_REFRESH_TOKEN is missing.');
        }

        $response = $this->http
            ->asForm()
            ->acceptJson()
            ->post('https://accounts.livechat.com/v2/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
            ]);

        $response->throw();

        $payload = $response->json();
        $this->persistAgentToken($payload);

        return $payload;
    }

    public function persistAgentToken(array $payload): void
    {
        $accessToken = (string) ($payload['access_token'] ?? '');

        if ($accessToken === '') {
            throw new RuntimeException('Text.com OAuth response did not include access_token.');
        }

        $expiresAt = now()->addSeconds(max(60, (int) ($payload['expires_in'] ?? 28800) - 60))->toIso8601String();
        $values = [
            'TEXT_AGENT_ACCESS_TOKEN' => $accessToken,
            'TEXT_AGENT_TOKEN_EXPIRES_AT' => $expiresAt,
        ];

        if (! empty($payload['refresh_token'])) {
            $values['TEXT_AGENT_REFRESH_TOKEN'] = (string) $payload['refresh_token'];
        }

        if (! empty($payload['organization_id'])) {
            $values['TEXT_ORGANIZATION_ID'] = (string) $payload['organization_id'];
        }

        $this->writeEnvValues($values);
    }

    public function validAgentAccessToken(): string
    {
        $token = (string) config('services.text.agent_access_token', '');

        if ($token === '') {
            throw new RuntimeException('TEXT_AGENT_ACCESS_TOKEN is missing.');
        }

        $expiresAt = (string) config('services.text.agent_token_expires_at', '');

        if ($expiresAt !== '' && now()->greaterThanOrEqualTo(Carbon::parse($expiresAt))) {
            $payload = $this->refreshAgentToken();

            return (string) $payload['access_token'];
        }

        return $token;
    }

    private function writeEnvValues(array $values): void
    {
        $path = base_path('.env');

        if (! File::exists($path)) {
            throw new RuntimeException('.env file was not found.');
        }

        $content = File::get($path);

        foreach ($values as $key => $value) {
            $line = $key.'='.$this->escapeEnvValue((string) $value);

            if (preg_match('/^'.preg_quote($key, '/').'=.*/m', $content)) {
                $content = preg_replace('/^'.preg_quote($key, '/').'=.*/m', $line, $content);
            } else {
                $content = rtrim($content).PHP_EOL.$line.PHP_EOL;
            }
        }

        File::put($path, $content);

        foreach ($values as $key => $value) {
            config(['services.text.'.strtolower(str_replace(['TEXT_', 'AGENT_'], ['', 'agent_'], $key)) => $value]);
        }
    }

    private function escapeEnvValue(string $value): string
    {
        if ($value === '' || preg_match('/\s|#|"|\'/', $value)) {
            return '"'.str_replace('"', '\"', $value).'"';
        }

        return $value;
    }

    private function clientId(): string
    {
        $clientId = (string) config('services.text.client_id', '');

        if ($clientId === '') {
            throw new RuntimeException('TEXT_CLIENT_ID is missing.');
        }

        return $clientId;
    }

    private function clientSecret(): string
    {
        $clientSecret = (string) config('services.text.client_secret', '');

        if ($clientSecret === '') {
            throw new RuntimeException('TEXT_CLIENT_SECRET is missing.');
        }

        return $clientSecret;
    }

    private function redirectUri(): string
    {
        $redirectUri = (string) config('services.text.redirect_uri', '');

        if ($redirectUri === '') {
            throw new RuntimeException('TEXT_REDIRECT_URI is missing.');
        }

        return $redirectUri;
    }
}
