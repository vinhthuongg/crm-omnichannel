<?php

namespace Modules\Dashboard\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Customer\Models\CustomerChannel;
use Modules\Facebook\Models\FacebookPage;

class DashboardChannelService
{
    /** Nhận ConversationVisibilityService để kiểm tra phạm vi truy cập. */
    public function __construct(private readonly ConversationVisibilityService $visibility)
    {
    }

    /** Tạo thẻ nguồn Facebook/Zalo gồm số khách, hội thoại và số chưa đọc. */
    public function sources(User $user): array
    {
        return collect(['facebook', 'zalo'])
            ->map(fn (string $channel): array => [
                'label' => ucfirst($channel),
                'value' => $this->filter($this->visibility->visibleFor($user), $channel)->count(),
            ])
            ->filter(fn (array $source): bool => $source['value'] > 0)
            ->values()
            ->all();
    }

    /** Đếm khách hàng và hội thoại chưa đọc theo từng kênh Facebook, Zalo. */
    public function metrics(User $user): Collection
    {
        $channels = CustomerChannel::query()->selectRaw('channel, count(*) as customers_count')
            ->groupBy('channel')->orderBy('channel')->get()->keyBy('channel');
        $unread = collect(['facebook', 'zalo'])->mapWithKeys(function (string $channel) use ($user): array {
            $query = $this->visibility->visibleFor($user)->where('unread_messages_count', '>', 0)
                ->whereIn('status', ConversationStatus::ACTIVE);

            return [$channel => $this->filter($query, $channel)->count()];
        });

        return collect(['facebook', 'zalo'])->map(fn (string $channel): array => [
            'name' => ucfirst($channel),
            'customers' => (int) ($channels[$channel]->customers_count ?? 0),
            'conversations' => $this->filter($this->visibility->visibleFor($user), $channel)->count(),
            'unread_messages' => (int) ($unread[$channel] ?? 0),
        ]);
    }

    /** Tạo dữ liệu quản lý kết nối Facebook Page và trạng thái đồng bộ từng kênh. */
    public function management(): array
    {
        $cards = FacebookPage::query()->latest('updated_at')->get()
            ->map(function (FacebookPage $page): array {
                $tokenInvalid = ($page->token_status ?? 'valid') === 'invalid';
                $webhookHealthy = $page->subscribed_at && ! $tokenInvalid;

                return [
                    'key' => 'facebook-'.$page->getKey(), 'title' => $page->page_name ?: 'Facebook Page',
                    'channel' => 'Facebook', 'icon' => 'thumb_up', 'icon_label' => null, 'connected' => true,
                    'status' => $tokenInvalid ? 'Cần kết nối lại' : 'Đang hoạt động',
                    'status_tone' => $tokenInvalid ? 'failed' : 'healthy', 'account' => $page->page_id,
                    'last_sync' => $this->lastFacebookPageSync($page) ?: 'Chưa có',
                    'webhook' => $webhookHealthy ? 'Hoạt động' : 'Cần kiểm tra',
                    'webhook_tone' => $webhookHealthy ? 'healthy' : 'failed',
                    'connect_url' => route('facebook.redirect'),
                    'sync_url' => route('facebook.pages.sync-messages', $page),
                    'disconnect_url' => route('facebook.pages.destroy', $page),
                ];
            })->values();

        if (trim((string) config('services.zalo.access_token')) !== '') {
            $cards->push([
                'key' => 'zalo', 'title' => (string) config('services.zalo.oa_name', 'Zalo OA'),
                'channel' => 'Zalo', 'icon' => null, 'icon_label' => 'Zalo', 'connected' => true,
                'status' => 'Đang hoạt động', 'status_tone' => 'healthy',
                'account' => (string) config('services.zalo.oa_id', 'Đã cấu hình'),
                'last_sync' => $this->lastChannelSync('zalo') ?: 'Chưa có',
                'webhook' => 'Hoạt động', 'webhook_tone' => 'healthy',
                'connect_url' => route('crm.settings', ['panel' => 'zalo']), 'sync_url' => null,
                'disconnect_url' => null,
            ]);
        }

        return [
            'title' => 'Quản lý kết nối',
            'subtitle' => 'Theo dõi các kênh đã kết nối thật trong hệ thống CRM.',
            'add_url' => route('facebook.redirect'),
            'cards' => $cards,
        ];
    }

    /** Trả thời điểm tin nhắn gần nhất của một Facebook Page dưới dạng ISO. */
    private function lastFacebookPageSync(FacebookPage $page): ?string
    {
        $syncedAt = Conversation::query()->where('facebook_page_id', $page->page_id)
            ->latest('last_message_at')->value('last_message_at');

        return $syncedAt ? Carbon::parse($syncedAt)->format('H:i d/m/Y') : null;
    }

    /** Trả thời điểm tin nhắn gần nhất trên toàn bộ hội thoại của một kênh. */
    private function lastChannelSync(string $channel): ?string
    {
        $syncedAt = $this->filter(Conversation::query(), $channel)->latest('last_message_at')->value('last_message_at');

        return $syncedAt ? Carbon::parse($syncedAt)->format('H:i d/m/Y') : null;
    }

    /** Giới hạn truy vấn hội thoại theo kênh của khách hàng hoặc kênh của tin nhắn. */
    private function filter(Builder $query, string $channel): Builder
    {
        return $query->where(function (Builder $query) use ($channel): void {
            $query->whereHas('customer.channels', fn (Builder $channels) => $channels->where('channel', $channel))
                ->orWhereHas('messages', fn (Builder $messages) => $messages->where('channel', $channel));
        });
    }
}
