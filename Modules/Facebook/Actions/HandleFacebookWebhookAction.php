<?php

namespace Modules\Facebook\Actions;

use Illuminate\Support\Facades\Log;
use Modules\Facebook\DTO\FacebookWebhookMessageData;
use Modules\Facebook\Jobs\KeepTypingUntilBotReplyJob;
use Modules\Facebook\Services\FacebookWebhookEchoHandler;
use Modules\Facebook\Services\FacebookWebhookProfileResolver;
use Modules\Message\Models\Message;
use Modules\Message\Services\MessageService;

class HandleFacebookWebhookAction
{
    /** Nhận dịch vụ lưu tin, xử lý echo và tìm hồ sơ sender để nhập từng Facebook webhook event. */
    public function __construct(private readonly MessageService $messages, private readonly FacebookWebhookEchoHandler $echoes,
        private readonly FacebookWebhookProfileResolver $profiles) {}

    /** Duyệt các messaging event, xử lý echo hoặc lưu tin đến cùng hồ sơ sender. */
    public function execute(array $payload): ?Message
    {
        $events = $this->events($payload);
        [$stored, $skipped, $last] = [0, 0, null];
        foreach ($events as $event) {
            if (! filled(data_get($event, 'message'))) { $skipped++; continue; }
            if ((bool) data_get($event, 'message.is_echo')) {
                $last = $this->echoes->handle($event);
                $last ? $stored++ : $skipped++;
                continue;
            }
            $senderId = (string) data_get($event, 'sender.id');
            $pageId = (string) data_get($event, 'recipient.id');
            if ($senderId === '') { $skipped++; continue; }
            $last = $this->messages->storeInbound(FacebookWebhookMessageData::fromMessagingEvent($event, $this->profiles->resolve($senderId, $pageId)));
            if ($last && $pageId !== '') KeepTypingUntilBotReplyJob::start($last->conversation, (int) $last->id, $senderId, $pageId);
            $stored++;
        }
        Log::info('Facebook webhook handled', ['events' => count($events), 'stored_messages' => $stored, 'skipped_events' => $skipped,
            'page_ids' => $this->ids($events, 'recipient.id'), 'sender_ids' => $this->ids($events, 'sender.id')]);
        return $last;
    }

    /** Trích xuất events từ Facebook webhook payload đã nhận. */
    private function events(array $payload): array
    {
        $events = [];
        foreach ((array) data_get($payload, 'entry', []) as $entry)
            foreach ((array) data_get($entry, 'messaging', []) as $event) if (is_array($event)) $events[] = $event;
        return $events;
    }

    /** Trích xuất ids từ Facebook webhook payload đã nhận. */
    private function ids(array $events, string $path): array
    {
        return collect($events)->map(fn (array $event): string => (string) data_get($event, $path))->filter()->unique()->values()->all();
    }
}
