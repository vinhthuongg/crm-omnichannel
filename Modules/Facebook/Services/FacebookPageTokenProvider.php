<?php

namespace Modules\Facebook\Services;

use Modules\Conversation\Models\Conversation;
use Modules\Facebook\Models\FacebookPage;
use Modules\Facebook\Repositories\FacebookPageRepository;
use RuntimeException;

class FacebookPageTokenProvider
{
    /** Nhận FacebookTokenValidationService để kiểm tra token còn hiệu lực và đúng Facebook App; FacebookPageRepository để đọc và lưu dữ liệu. */
    public function __construct(
        private readonly FacebookTokenValidationService $tokens,
        private readonly FacebookPageRepository $pages,
    ) {
    }

    /** Tìm Page của hội thoại, kiểm tra token và trả Page access token hợp lệ. */
    public function forConversation(Conversation $conversation): ?string
    {
        $pageId = (string) $conversation->facebook_page_id;
        if ($pageId === '') return null;
        $page = FacebookPage::query()->where('page_id', $pageId)->first();
        if (! $page) return null;
        try {
            if ($page->token_status !== 'valid') {
                $this->tokens->ensurePageBelongsToMessengerApp($page->messenger_app_id);
                $this->pages->markValid($page, $this->tokens->validatePageToken($page->page_access_token));
            }
        } catch (\Throwable $exception) {
            $this->pages->markInvalid($page, $exception->getMessage());
            throw new RuntimeException('Facebook page token khong hop le. Vui long reconnect fanpage: '.$exception->getMessage(), previous: $exception);
        }
        return $page->page_access_token;
    }
}
