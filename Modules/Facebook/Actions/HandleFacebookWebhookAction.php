<?php

namespace Modules\Facebook\Actions;

use Illuminate\Support\Facades\Log;
use Modules\Facebook\DTO\FacebookWebhookMessageData;
use Modules\Facebook\Services\FacebookMessengerService;
use Modules\Message\Models\Message;
use Modules\Message\Services\MessageService;

class HandleFacebookWebhookAction
{
    public function __construct(
        private readonly MessageService $messages,
        private readonly FacebookMessengerService $facebook,
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

            if ($senderId === '') {
                $skipped++;
                continue;
            }

            $lastMessage = $this->messages->storeInbound(FacebookWebhookMessageData::fromMessagingEvent(
                $event,
                $this->facebook->profile($senderId),
            ));
            $stored++;
        }

        Log::info('Facebook webhook handled', [
            'events' => count($events),
            'stored_messages' => $stored,
            'skipped_events' => $skipped,
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
