<?php

namespace Modules\Text\Services;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;

class TextAgentChatService
{
    public function __construct(private readonly Http $http)
    {
    }

    public function configured(): bool
    {
        return $this->agentEmail() !== ''
            && $this->apiToken() !== ''
            && $this->humanGroupId() !== '';
    }

    public function transferChatToHuman(string $chatId): bool
    {
        if (! $this->configured() || $chatId === '') {
            return false;
        }

        $response = $this->http
            ->connectTimeout(3)
            ->timeout(8)
            ->withBasicAuth($this->agentEmail(), $this->apiToken())
            ->acceptJson()
            ->asJson()
            ->post($this->endpoint('/agent/action/transfer_chat'), [
                'id' => $chatId,
                'target' => [
                    'type' => 'group',
                    'ids' => [(int) $this->humanGroupId()],
                ],
                'ignore_requester_presence' => true,
                'ignore_agents_availability' => true,
            ]);

        if ($response->failed()) {
            Log::warning('Text.com transfer_chat failed', [
                'chat_id' => $chatId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('services.text.base_url', 'https://api.livechatinc.com/v3.6'), '/').$path;
    }

    private function agentEmail(): string
    {
        return (string) config('services.text.agent_email', '');
    }

    private function apiToken(): string
    {
        return (string) config('services.text.api_token', '');
    }

    private function humanGroupId(): string
    {
        return (string) config('services.text.human_group_id', '');
    }
}
