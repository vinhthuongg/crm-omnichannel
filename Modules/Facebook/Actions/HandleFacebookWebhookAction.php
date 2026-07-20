<?php

namespace Modules\Facebook\Actions;

use Illuminate\Support\Facades\Log;
use Modules\Facebook\DTO\FacebookWebhookMessageData;
use Modules\Facebook\Jobs\KeepTypingUntilBotReplyJob;
use Modules\Facebook\Models\FacebookPage;
use Modules\Facebook\Repositories\FacebookPageRepository;
use Modules\Facebook\Services\FacebookMessengerService;
use Modules\Facebook\Services\FacebookTokenValidationService;
use Modules\Customer\Models\CustomerChannel;
use Modules\Message\Models\Message;
use Modules\Message\Services\MessageService;

class HandleFacebookWebhookAction
{
    public function __construct(
        private readonly MessageService $messages,
        private readonly FacebookMessengerService $facebook,
        private readonly FacebookTokenValidationService $tokens,
        private readonly FacebookPageRepository $pages,
    )
    {
    }

    public function execute(array $payload): ?Message
    {
        $events = $this->messagingEvents($payload);
        $stored = 0;
        $skipped = 0;
        $lastMessage = null;

        foreach ($events as $event) {
            if (! filled(data_get($event, 'message'))) {
                $skipped++;
                continue;
            }

            $senderId = (string) data_get($event, 'sender.id');
            $pageId = (string) data_get($event, 'recipient.id');

            if ((bool) data_get($event, 'message.is_echo')) {
                $lastMessage = $this->messages->storeFacebookEcho($event);
                $this->sendTypingOffForEcho($event);

                if ($lastMessage) {
                    $stored++;
                } else {
                    $skipped++;
                }

                continue;
            }

            if ($senderId === '') {
                $skipped++;
                continue;
            }

            $page = $pageId !== ''
                ? FacebookPage::query()->where('page_id', $pageId)->first()
                : null;
            $pageToken = $page?->page_access_token;
            $profile = $this->cachedCustomerProfile($senderId);

            if ($this->shouldFetchProfile($profile) && $page && $pageToken) {
                try {
                    if ($page->token_status !== 'valid') {
                        $this->tokens->ensurePageBelongsToMessengerApp($page->messenger_app_id);
                        $debugToken = $this->tokens->validatePageToken($pageToken);
                        $this->pages->markValid($page, $debugToken);
                    }

                    $profile = array_filter([
                        ...$profile,
                        ...$this->facebook->profile($senderId, $pageToken),
                    ]);
                } catch (\Throwable $exception) {
                    $this->pages->markInvalid($page, $exception->getMessage());
                    Log::warning('Facebook webhook profile lookup skipped because page token is invalid', [
                        'page_id' => $pageId,
                        'sender_id' => $senderId,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $lastMessage = $this->messages->storeInbound(FacebookWebhookMessageData::fromMessagingEvent(
                $event,
                $profile,
            ));

            if ($lastMessage && $pageId !== '') {
                KeepTypingUntilBotReplyJob::start($lastMessage->conversation, (int) $lastMessage->id, $senderId, $pageId);
            }

            $stored++;
        }

        Log::info('Facebook webhook handled', [
            'events' => count($events),
            'stored_messages' => $stored,
            'skipped_events' => $skipped,
            'page_ids' => collect($events)
                ->map(fn (array $event): string => (string) data_get($event, 'recipient.id'))
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'sender_ids' => collect($events)
                ->map(fn (array $event): string => (string) data_get($event, 'sender.id'))
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ]);

        return $lastMessage;
    }

    private function sendTypingOffForEcho(array $event): void
    {
        $externalMessageId = (string) data_get($event, 'message.mid');
        $customerId = (string) data_get($event, 'recipient.id');
        $pageId = (string) data_get($event, 'sender.id');

        if ($externalMessageId === '' || $customerId === '' || $pageId === '') {
            return;
        }

        $isBotEcho = Message::query()
            ->where('external_message_id', $externalMessageId)
            ->where('sender_type', 'system')
            ->exists();

        if (! $isBotEcho) {
            return;
        }

        $page = FacebookPage::query()
            ->where('page_id', $pageId)
            ->first();

        if (! $page?->page_access_token) {
            return;
        }

        app()->terminating(function () use ($customerId, $page): void {
            $this->facebook->sendTypingOff($customerId, $page->page_access_token);
        });
    }

    private function cachedCustomerProfile(string $senderId): array
    {
        $channel = CustomerChannel::query()
            ->with('customer')
            ->where('channel', 'facebook')
            ->where('external_id', $senderId)
            ->first();

        if (! $channel?->customer) {
            return [];
        }

        $profile = (array) data_get($channel->metadata, 'profile', []);
        $customer = $channel->customer;

        return array_filter([
            ...$profile,
            'id' => $senderId,
            'name' => $customer->name && $customer->name !== $senderId ? $customer->name : data_get($profile, 'name'),
            'profile_pic' => $customer->avatar ?: data_get($profile, 'profile_pic'),
        ]);
    }

    private function shouldFetchProfile(array $profile): bool
    {
        return blank(data_get($profile, 'name')) || blank(data_get($profile, 'profile_pic'));
    }

    private function messagingEvents(array $payload): array
    {
        $events = [];

        foreach ((array) data_get($payload, 'entry', []) as $entry) {
            foreach ((array) data_get($entry, 'messaging', []) as $event) {
                if (is_array($event)) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }
}
