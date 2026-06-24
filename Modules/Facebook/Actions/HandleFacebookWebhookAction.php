<?php

namespace Modules\Facebook\Actions;

use Illuminate\Support\Facades\Log;
use Modules\Facebook\DTO\FacebookWebhookMessageData;
use Modules\Facebook\Models\FacebookPage;
use Modules\Facebook\Repositories\FacebookPageRepository;
use Modules\Facebook\Services\FacebookMessengerService;
use Modules\Facebook\Services\FacebookTokenValidationService;
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

            if ((bool) data_get($event, 'message.is_echo')) {
                $skipped++;
                continue;
            }

            $senderId = (string) data_get($event, 'sender.id');
            $pageId = (string) data_get($event, 'recipient.id');

            if ($senderId === '') {
                $skipped++;
                continue;
            }

            $page = $pageId !== ''
                ? FacebookPage::query()->where('page_id', $pageId)->first()
                : null;
            $pageToken = $page?->page_access_token;
            $profile = [];

            if ($page && $pageToken) {
                try {
                    $this->tokens->ensurePageBelongsToMessengerApp($page->messenger_app_id);
                    $debugToken = $this->tokens->validatePageToken($pageToken);
                    $this->pages->markValid($page, $debugToken);
                    $profile = $this->facebook->profile($senderId, $pageToken);
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
