<?php

namespace Modules\Text\Services;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TextAgentChatService
{
    public function __construct(private readonly Http $http)
    {
    }

    public function configured(): bool
    {
        return $this->apiToken() !== '';
    }

    public function bridgeEnabled(): bool
    {
        return (bool) config('services.text.bridge_enabled', false) && $this->configured();
    }

    public function startChat(array $customer, array $event, array $properties = []): array
    {
        $chat = [
            'users' => [
                array_filter([
                    'id' => $customer['id'] ?? null,
                    'name' => $customer['name'] ?? null,
                    'type' => 'customer',
                    'email' => $customer['email'] ?? null,
                    'avatar' => $customer['avatar'] ?? null,
                ], fn ($value): bool => filled($value)),
            ],
            'thread' => [
                'events' => [$event],
            ],
        ];

        if ($this->defaultGroupId() !== '') {
            $chat['access'] = ['group_ids' => [(int) $this->defaultGroupId()]];
        }

        if ($properties) {
            $chat['properties'] = $properties;
        }

        return $this->post('/agent/action/start_chat', [
            'chat' => $chat,
            'continuous' => true,
        ])->json();
    }

    public function resumeChat(string $chatId, array $event, ?string $customerId = null): array
    {
        $chat = [
            'id' => $chatId,
            'thread' => [
                'events' => [$event],
            ],
        ];

        if ($customerId) {
            $chat['users'] = [
                [
                    'id' => $customerId,
                    'type' => 'customer',
                ],
            ];
        }

        if ($this->defaultGroupId() !== '') {
            $chat['access'] = ['group_ids' => [(int) $this->defaultGroupId()]];
        }

        return $this->post('/agent/action/resume_chat', [
            'chat' => $chat,
            'continuous' => true,
        ])->json();
    }

    public function sendEvent(string $chatId, array $event): array
    {
        return $this->post('/agent/action/send_event', [
            'chat_id' => $chatId,
            'event' => $event,
        ])->json();
    }

    public function addCustomerToChat(string $chatId, string $customerId): bool
    {
        if ($chatId === '' || $customerId === '') {
            return false;
        }

        try {
            $this->post('/agent/action/add_user_to_chat', [
                'chat_id' => $chatId,
                'user_id' => $customerId,
                'user_type' => 'customer',
                'visibility' => 'all',
                'ignore_requester_presence' => true,
            ]);

            return true;
        } catch (\Throwable $exception) {
            Log::info('Text.com add_customer_to_chat skipped', [
                'chat_id' => $chatId,
                'customer_id' => $customerId,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function transferChatToHuman(string $chatId): bool
    {
        if (! $this->configured() || $chatId === '' || $this->humanGroupId() === '') {
            return false;
        }

        $response = $this->http
            ->connectTimeout(3)
            ->timeout(8)
            ->withHeaders(['Authorization' => 'Basic '.$this->apiToken()])
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

    private function post(string $path, array $payload): Response
    {
        if (! $this->configured()) {
            throw new RuntimeException('Text.com Agent Chat API is not configured.');
        }

        $response = $this->http
            ->connectTimeout(5)
            ->timeout(20)
            ->withHeaders(['Authorization' => 'Basic '.$this->apiToken()])
            ->acceptJson()
            ->asJson()
            ->post($this->endpoint($path), $payload);

        $response->throw();

        return $response;
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

    private function defaultGroupId(): string
    {
        return (string) config('services.text.default_group_id', '');
    }
}
