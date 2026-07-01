<?php

namespace Modules\Facebook\Services;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;
use Modules\Conversation\Models\Conversation;
use Modules\Customer\Models\CustomerChannel;
use Modules\Facebook\Models\FacebookPage;

class FacebookThreadControlService
{
    public function __construct(private readonly Http $http)
    {
    }

    public function takeThreadControl(Conversation $conversation): bool
    {
        $page = $conversation->facebook_page_id
            ? FacebookPage::query()->where('page_id', $conversation->facebook_page_id)->first()
            : null;
        $psid = CustomerChannel::query()
            ->where('customer_id', $conversation->customer_id)
            ->where('channel', 'facebook')
            ->value('external_id');

        if (! $page?->page_access_token || ! $psid) {
            Log::info('Facebook thread control skipped because page token or PSID is missing', [
                'conversation_id' => $conversation->id,
                'facebook_page_id' => $conversation->facebook_page_id,
                'has_page_token' => (bool) $page?->page_access_token,
                'has_psid' => (bool) $psid,
            ]);

            return false;
        }

        $response = $this->http
            ->connectTimeout(3)
            ->timeout(8)
            ->asJson()
            ->post($this->graphUrl('/me/take_thread_control'), [
                'recipient' => ['id' => $psid],
                'metadata' => 'CRM human reply takeover',
                'access_token' => $page->page_access_token,
            ]);

        if ($response->successful()) {
            Log::info('Facebook thread control taken by CRM', [
                'conversation_id' => $conversation->id,
                'facebook_page_id' => $conversation->facebook_page_id,
                'psid' => $psid,
            ]);

            return true;
        }

        Log::warning('Facebook take_thread_control failed', [
            'conversation_id' => $conversation->id,
            'facebook_page_id' => $conversation->facebook_page_id,
            'psid' => $psid,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        $this->requestThreadControl($page, (string) $psid, $conversation);

        return false;
    }

    public function passThreadControlToBot(Conversation $conversation, ?string $metadata = null): bool
    {
        $page = $conversation->facebook_page_id
            ? FacebookPage::query()->where('page_id', $conversation->facebook_page_id)->first()
            : null;
        $psid = CustomerChannel::query()
            ->where('customer_id', $conversation->customer_id)
            ->where('channel', 'facebook')
            ->value('external_id');

        if (! $page?->page_access_token || ! $psid) {
            Log::info('Facebook pass_thread_control skipped because page token or PSID is missing', [
                'conversation_id' => $conversation->id,
                'facebook_page_id' => $conversation->facebook_page_id,
                'has_page_token' => (bool) $page?->page_access_token,
                'has_psid' => (bool) $psid,
            ]);

            return false;
        }

        $targetAppId = $this->resolveBotTargetAppId($page);

        $payload = [
            'recipient' => ['id' => $psid],
            'metadata' => $metadata ?: 'CRM inactivity timeout, returning thread to bot',
            'access_token' => $page->page_access_token,
        ];

        if ($targetAppId !== '') {
            $payload['target_app_id'] = $targetAppId;
        }

        $response = $this->http
            ->connectTimeout(3)
            ->timeout(8)
            ->asJson()
            ->post($this->graphUrl('/me/pass_thread_control'), $payload);

        if ($response->successful()) {
            Log::info('Facebook thread control passed back to bot', [
                'conversation_id' => $conversation->id,
                'facebook_page_id' => $conversation->facebook_page_id,
                'psid' => $psid,
                'target_app_id' => $targetAppId ?: null,
                'fallback_receiver' => $targetAppId === '' ? 'primary_receiver' : null,
            ]);

            return true;
        }

        Log::warning('Facebook pass_thread_control failed', [
            'conversation_id' => $conversation->id,
            'facebook_page_id' => $conversation->facebook_page_id,
            'psid' => $psid,
            'target_app_id' => $targetAppId ?: null,
            'fallback_receiver' => $targetAppId === '' ? 'primary_receiver' : null,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return false;
    }

    private function resolveBotTargetAppId(FacebookPage $page): string
    {
        $configuredAppId = trim((string) config('services.text.messenger_app_id', ''));

        if ($configuredAppId !== '') {
            return $configuredAppId;
        }

        $response = $this->http
            ->connectTimeout(3)
            ->timeout(8)
            ->get($this->graphUrl('/me/messenger_profile'), [
                'fields' => 'primary_receiver,secondary_receivers',
                'access_token' => $page->page_access_token,
            ]);

        if ($response->failed()) {
            Log::warning('Facebook messenger receiver discovery failed', [
                'facebook_page_id' => $page->page_id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return '';
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            Log::warning('Facebook messenger receiver discovery returned an invalid payload', [
                'facebook_page_id' => $page->page_id,
                'body' => $response->body(),
            ]);

            return '';
        }

        $ownAppId = trim((string) config('services.facebook.messenger_app_id', ''));
        $receivers = [];

        if (is_array($payload['primary_receiver'] ?? null)) {
            $receivers[] = $payload['primary_receiver'];
        }

        foreach (($payload['secondary_receivers'] ?? []) as $receiver) {
            if (is_array($receiver)) {
                $receivers[] = $receiver;
            }
        }

        $candidates = collect($receivers)
            ->map(fn (array $receiver) => [
                'id' => trim((string) ($receiver['id'] ?? '')),
                'name' => trim((string) ($receiver['name'] ?? '')),
            ])
            ->filter(fn (array $receiver) => $receiver['id'] !== '' && $receiver['id'] !== $ownAppId)
            ->values();

        $matched = $candidates->first(fn (array $receiver) => preg_match('/text|chatbot|livechat/i', $receiver['name']) === 1);

        if (! $matched && $candidates->count() === 1) {
            $matched = $candidates->first();
        }

        if (! $matched) {
            Log::warning('Facebook bot receiver app id was not found in messenger profile', [
                'facebook_page_id' => $page->page_id,
                'receivers' => $candidates->all(),
            ]);

            return '';
        }

        Log::info('Facebook bot receiver app id discovered from messenger profile', [
            'facebook_page_id' => $page->page_id,
            'target_app_id' => $matched['id'],
            'receiver_name' => $matched['name'],
        ]);

        return $matched['id'];
    }

    private function requestThreadControl(FacebookPage $page, string $psid, Conversation $conversation): void
    {
        $response = $this->http
            ->connectTimeout(3)
            ->timeout(8)
            ->asJson()
            ->post($this->graphUrl('/me/request_thread_control'), [
                'recipient' => ['id' => $psid],
                'metadata' => 'CRM human reply takeover requested',
                'access_token' => $page->page_access_token,
            ]);

        if ($response->failed()) {
            Log::warning('Facebook request_thread_control failed', [
                'conversation_id' => $conversation->id,
                'facebook_page_id' => $conversation->facebook_page_id,
                'psid' => $psid,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.((string) config('services.facebook.graph_version', 'v25.0')).$path;
    }
}
