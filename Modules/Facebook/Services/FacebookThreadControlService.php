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
