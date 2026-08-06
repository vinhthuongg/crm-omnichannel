<?php

namespace Modules\Facebook\Services;

use Illuminate\Support\Facades\Log;
use Modules\Customer\Models\CustomerChannel;
use Modules\Facebook\Models\FacebookPage;
use Modules\Facebook\Repositories\FacebookPageRepository;

class FacebookWebhookProfileResolver
{
    /** Khởi tạo resolver với Messenger gateway, token validator và kho Page. */
    public function __construct(private readonly FacebookMessengerService $facebook, private readonly FacebookTokenValidationService $tokens,
        private readonly FacebookPageRepository $pages) {}

    /** Tìm hồ sơ sender từ cache hoặc Graph API bằng token đúng Page. */
    public function resolve(string $senderId, string $pageId): array
    {
        $profile = $this->cached($senderId);
        $page = $pageId !== '' ? FacebookPage::query()->where('page_id', $pageId)->first() : null;
        if (! $this->incomplete($profile) || ! $page?->page_access_token) return $profile;
        try {
            if ($page->token_status !== 'valid') {
                $this->tokens->ensurePageBelongsToMessengerApp($page->messenger_app_id);
                $this->pages->markValid($page, $this->tokens->validatePageToken($page->page_access_token));
            }
            return array_filter([...$profile, ...$this->facebook->profile($senderId, $page->page_access_token)]);
        } catch (\Throwable $e) {
            $this->pages->markInvalid($page, $e->getMessage());
            Log::warning('Facebook webhook profile lookup skipped because page token is invalid',
                ['page_id' => $pageId, 'sender_id' => $senderId, 'error' => $e->getMessage()]);
            return $profile;
        }
    }

    /** Đọc hồ sơ khách hàng đã lưu trước đó theo Facebook sender ID */
    private function cached(string $senderId): array
    {
        $channel = CustomerChannel::query()->with('customer')->where('channel', 'facebook')->where('external_id', $senderId)->first();
        if (! $channel?->customer) return [];
        $profile = (array) data_get($channel->metadata, 'profile', []);
        return array_filter([...$profile, 'id' => $senderId,
            'name' => $channel->customer->name && $channel->customer->name !== $senderId ? $channel->customer->name : data_get($profile, 'name'),
            'profile_pic' => $channel->customer->avatar ?: data_get($profile, 'profile_pic')]);
    }

    /** Xác định hồ sơ cache còn thiếu dữ liệu cần làm mới. */
    private function incomplete(array $profile): bool
    {
        return blank(data_get($profile, 'name')) || blank(data_get($profile, 'profile_pic'));
    }
}
