<?php

namespace Modules\Facebook\Actions;

use Illuminate\Support\Facades\Log;
use Modules\Facebook\Models\FacebookPage;
use Modules\Facebook\Services\FacebookOAuthService;

class DisconnectFacebookPageAction
{
    /** Nhận dịch vụ Facebook OAuth để hủy đăng ký webhook của Page trước khi xóa kết nối local. */
    public function __construct(private readonly FacebookOAuthService $facebook)
    {
    }

    /** Hủy Page khỏi subscribed_apps nếu Facebook còn chấp nhận token, sau đó xóa kết nối nhưng giữ lịch sử CRM. */
    public function execute(FacebookPage $page): bool
    {
        $unsubscribed = true;

        try {
            $this->facebook->unsubscribePage($page->page_id, $page->page_access_token);
        } catch (\Throwable $exception) {
            $unsubscribed = false;
            Log::warning('Facebook Page was removed locally but could not be unsubscribed remotely', [
                'facebook_page_id' => $page->getKey(),
                'page_id' => $page->page_id,
                'error' => $exception->getMessage(),
            ]);
        }

        $page->delete();

        return $unsubscribed;
    }
}
