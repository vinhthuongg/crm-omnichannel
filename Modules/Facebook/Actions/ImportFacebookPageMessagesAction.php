<?php

namespace Modules\Facebook\Actions;

use App\Models\User;
use Modules\Facebook\Models\FacebookPage;
use Modules\Facebook\Services\FacebookConversationImportService;

class ImportFacebookPageMessagesAction
{
    /** Nhận FacebookConversationImportService để nhập hội thoại và tin nhắn lịch sử từ Page. */
    public function __construct(private readonly FacebookConversationImportService $importer)
    {
    }

    /** Nhập lịch sử hội thoại/tin nhắn của Page và trả số bản ghi đã đồng bộ. */
    public function execute(User $user, FacebookPage $page, int $conversationLimit = 20): array
    {
        abort_unless($page->user_id === $user->id || $user->can('conversation.view_all'), 403);

        return $this->importer->importPage($page, $conversationLimit);
    }
}
