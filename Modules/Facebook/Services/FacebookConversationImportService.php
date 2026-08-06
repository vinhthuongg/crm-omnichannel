<?php

namespace Modules\Facebook\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Modules\Facebook\Models\FacebookPage;
use Modules\Facebook\Repositories\FacebookPageRepository;

class FacebookConversationImportService
{
    private const MAX_MESSAGE_PAGES = 10;

    /** Nhận FacebookTokenValidationService để kiểm tra token còn hiệu lực và đúng Facebook App; FacebookPageRepository để đọc và lưu dữ liệu; FacebookGraphClient để giao tiếp với dịch vụ bên ngoài; FacebookImportedMessagePersister để lưu khách hàng, hội thoại và tin Facebook đã nhập. */
    public function __construct(
        private readonly FacebookTokenValidationService $tokens,
        private readonly FacebookPageRepository $pages,
        private readonly FacebookGraphClient $graph,
        private readonly FacebookImportedMessagePersister $messages,
    ) {
    }

    /** Nhập và đồng bộ dữ liệu hội thoại Facebook tại bước importPage. */
    public function importPage(FacebookPage $page, int $conversationLimit = 100): array
    {
        $this->tokens->ensurePageBelongsToMessengerApp($page->messenger_app_id);
        $this->pages->markValid($page, $this->tokens->validatePageToken($page->page_access_token));
        $stats = ['conversations' => 0, 'messages' => 0];
        $url = $this->graph->url("/{$page->page_id}/conversations");
        $params = ['fields' => 'id,participants,updated_time,messages.limit(100){id,message,from,to,created_time,attachments}',
            'limit' => min(max($conversationLimit, 1), 100), 'access_token' => $page->page_access_token];
        $seen = [];
        do {
            if (isset($seen[$url])) { $this->repeatedPage($url, $page, null); break; }
            $seen[$url] = true;
            $payload = $this->graph->get($url, $params, $page->page_access_token);
            foreach ((array) Arr::get($payload, 'data', []) as $conversation) {
                $stats['conversations']++;
                $stats['messages'] += $this->importConversation($page, $conversation);
            }
            $url = Arr::get($payload, 'paging.next');
            $params = [];
        } while ($url);
        return $stats;
    }

    /** Nhập và đồng bộ dữ liệu hội thoại Facebook tại bước importConversation. */
    private function importConversation(FacebookPage $page, array $conversation): int
    {
        $imported = 0;
        $embedded = (array) Arr::get($conversation, 'messages.data', []);
        $conversationId = (string) Arr::get($conversation, 'id');
        foreach ($embedded as $message) if ($this->messages->store($page, $message, $conversation)) $imported++;
        $url = Arr::get($conversation, 'messages.paging.next');
        if (! $url && $embedded === []) $url = $this->graph->url('/'.$conversationId.'/messages');
        if (! $url) { $this->messages->refreshTimestamp($conversationId); return $imported; }

        $params = ['fields' => 'id,message,from,to,created_time,attachments', 'limit' => 100, 'access_token' => $page->page_access_token];
        $seen = []; $pageCount = 0;
        do {
            if (isset($seen[$url])) { $this->repeatedPage($url, $page, $conversationId); break; }
            if ($pageCount >= self::MAX_MESSAGE_PAGES) {
                Log::info('Facebook message sync stopped at per-conversation page limit', ['limit' => self::MAX_MESSAGE_PAGES,
                    'facebook_page_id' => $page->page_id, 'conversation_id' => $conversationId]);
                break;
            }
            $seen[$url] = true; $pageCount++;
            $payload = $this->graph->get($url, $params, $page->page_access_token);
            foreach ((array) Arr::get($payload, 'data', []) as $message) if ($this->messages->store($page, $message, $conversation)) $imported++;
            $url = Arr::get($payload, 'paging.next'); $params = [];
        } while ($url);
        $this->messages->refreshTimestamp($conversationId);
        return $imported;
    }

    /** Nhập và đồng bộ dữ liệu hội thoại Facebook tại bước repeatedPage. */
    private function repeatedPage(string $url, FacebookPage $page, ?string $conversationId): void
    {
        Log::warning('Facebook sync stopped because paging URL repeated', ['url' => $this->graph->safeUrl($url),
            'page_id' => $page->page_id, 'conversation_id' => $conversationId]);
    }
}
