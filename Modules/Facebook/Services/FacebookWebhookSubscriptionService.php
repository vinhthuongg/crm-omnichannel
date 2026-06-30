<?php

namespace Modules\Facebook\Services;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Arr;
use RuntimeException;

class FacebookWebhookSubscriptionService
{
    private const PAGE_FIELDS = 'messages,messaging_postbacks,message_deliveries,message_reads';
    private const PAGE_FIELDS_WITH_ECHOES = 'messages,message_echoes,messaging_postbacks,message_deliveries,message_reads';

    public function __construct(private readonly Http $http)
    {
    }

    public function ensureAppPageWebhook(): array
    {
        $this->ensureConfigured();

        if ($this->hasCurrentPageWebhook()) {
            return [
                'success' => true,
                'skipped' => true,
                'callback_url' => $this->callbackUrl(),
            ];
        }

        $response = $this->http
            ->connectTimeout(5)
            ->timeout(15)
            ->asForm()
            ->post($this->graphUrl('/'.$this->appId().'/subscriptions'), [
                'object' => 'page',
                'callback_url' => $this->callbackUrl(),
                'verify_token' => $this->verifyToken(),
                'fields' => self::PAGE_FIELDS_WITH_ECHOES,
                'include_values' => 'true',
                'access_token' => $this->appAccessToken(),
            ]);

        try {
            $response->throw();
        } catch (\Throwable $exception) {
            if (! str_contains($exception->getMessage(), 'message_echoes')) {
                throw $exception;
            }

            $response = $this->http
                ->connectTimeout(5)
                ->timeout(15)
                ->asForm()
                ->post($this->graphUrl('/'.$this->appId().'/subscriptions'), [
                    'object' => 'page',
                    'callback_url' => $this->callbackUrl(),
                    'verify_token' => $this->verifyToken(),
                    'fields' => self::PAGE_FIELDS,
                    'include_values' => 'true',
                    'access_token' => $this->appAccessToken(),
                ]);

            $response->throw();
        }

        return $response->json();
    }

    public function hasCurrentPageWebhook(): bool
    {
        foreach ($this->pageSubscriptions() as $subscription) {
            if ((string) Arr::get($subscription, 'object') !== 'page') {
                continue;
            }

            $callbackUrl = (string) Arr::get($subscription, 'callback_url');
            $fields = collect((array) Arr::get($subscription, 'fields', []))
                ->map(fn ($field): string => is_array($field) ? (string) Arr::get($field, 'name') : (string) $field)
                ->filter()
                ->values();

            if ($callbackUrl === $this->callbackUrl() && $this->hasRequiredFields($fields->all())) {
                return true;
            }
        }

        return false;
    }

    public function pageSubscriptions(): array
    {
        $this->ensureConfigured();

        $response = $this->http
            ->connectTimeout(5)
            ->timeout(15)
            ->get($this->graphUrl('/'.$this->appId().'/subscriptions'), [
                'access_token' => $this->appAccessToken(),
            ]);

        $response->throw();

        return (array) Arr::get($response->json(), 'data', []);
    }

    public function callbackUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/webhook/facebook';
    }

    public function subscribedFields(): string
    {
        return self::PAGE_FIELDS_WITH_ECHOES;
    }

    private function hasRequiredFields(array $fields): bool
    {
        $fieldLookup = array_flip($fields);

        foreach (explode(',', self::PAGE_FIELDS_WITH_ECHOES) as $field) {
            if (! array_key_exists($field, $fieldLookup)) {
                return false;
            }
        }

        return true;
    }

    private function ensureConfigured(): void
    {
        if ($this->appId() === '' || $this->appSecret() === '') {
            throw new RuntimeException('FACEBOOK_CLIENT_ID or FACEBOOK_CLIENT_SECRET is missing in .env.');
        }

        if ($this->verifyToken() === '') {
            throw new RuntimeException('FACEBOOK_VERIFY_TOKEN is missing in .env.');
        }

        if ($this->callbackUrl() === '' || ! str_starts_with($this->callbackUrl(), 'https://')) {
            throw new RuntimeException('APP_URL must be an HTTPS URL before configuring Facebook webhooks.');
        }
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

    private function appAccessToken(): string
    {
        return $this->appId().'|'.$this->appSecret();
    }

    private function verifyToken(): string
    {
        return (string) config('services.facebook.verify_token');
    }
}
