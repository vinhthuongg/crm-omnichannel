<?php

namespace Modules\Text\Services;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use RuntimeException;

class TextCustomerChatService
{
    public function __construct(private readonly Http $http)
    {
    }

    public function configured(): bool
    {
        return $this->clientId() !== ''
            && $this->organizationId() !== ''
            && $this->agentAccessToken() !== '';
    }

    public function issueCustomerToken(?string $entityId = null): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Text.com Customer Chat API is not configured.');
        }

        $payload = [
            'grant_type' => 'agent_token',
            'client_id' => $this->clientId(),
            'response_type' => 'token',
            'organization_id' => $this->organizationId(),
        ];

        if ($entityId) {
            $payload['entity_id'] = $entityId;
        }

        $response = $this->http
            ->connectTimeout(5)
            ->timeout(20)
            ->withToken($this->agentAccessToken())
            ->acceptJson()
            ->asJson()
            ->post('https://accounts.livechat.com/v2/customer/token', $payload);

        $response->throw();

        return $response->json();
    }

    public function startChat(string $customerToken, array $event): array
    {
        return $this->post($customerToken, '/customer/action/start_chat', [
            'chat' => $this->chatPayload([
                'thread' => [
                    'events' => [$event],
                ],
            ]),
            'continuous' => true,
        ])->json();
    }

    public function resumeChat(string $customerToken, string $chatId, array $event): array
    {
        return $this->post($customerToken, '/customer/action/resume_chat', [
            'chat' => $this->chatPayload([
                'id' => $chatId,
                'thread' => [
                    'events' => [$event],
                ],
            ]),
            'continuous' => true,
        ])->json();
    }

    public function sendEvent(string $customerToken, string $chatId, array $event): array
    {
        return $this->post($customerToken, '/customer/action/send_event', [
            'chat_id' => $chatId,
            'event' => $event,
            'attach_to_last_thread' => true,
        ])->json();
    }

    public function tokenExpiresAt(array $tokenPayload): ?CarbonInterface
    {
        $seconds = (int) ($tokenPayload['expires_in'] ?? 0);

        return $seconds > 0 ? now()->addSeconds(max(60, $seconds - 60)) : null;
    }

    private function chatPayload(array $chat): array
    {
        if ($this->defaultGroupId() !== '') {
            $chat['access'] = ['group_ids' => [(int) $this->defaultGroupId()]];
        }

        return $chat;
    }

    private function post(string $customerToken, string $path, array $payload): Response
    {
        if ($customerToken === '') {
            throw new RuntimeException('Text.com customer access token is missing.');
        }

        $response = $this->http
            ->connectTimeout(5)
            ->timeout(20)
            ->withToken($customerToken)
            ->acceptJson()
            ->asJson()
            ->post($this->endpoint($path), $payload);

        $response->throw();

        return $response;
    }

    private function endpoint(string $path): string
    {
        $base = rtrim((string) config('services.text.base_url', 'https://api.livechatinc.com/v3.6'), '/').$path;

        return $base.'?organization_id='.rawurlencode($this->organizationId());
    }

    private function clientId(): string
    {
        return (string) config('services.text.client_id', '');
    }

    private function organizationId(): string
    {
        return (string) config('services.text.organization_id', '');
    }

    private function agentAccessToken(): string
    {
        return (string) config('services.text.agent_access_token', '');
    }

    private function defaultGroupId(): string
    {
        return (string) config('services.text.default_group_id', '');
    }
}
