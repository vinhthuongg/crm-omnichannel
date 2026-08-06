<?php

namespace Modules\Facebook\Services;

use Illuminate\Support\Arr;

class FacebookWebhookSubscriptionService
{
    private const FIELDS = 'messages,messaging_postbacks,message_deliveries,message_reads';
    private const FIELDS_WITH_ECHOES = 'messages,message_echoes,messaging_postbacks,message_deliveries,message_reads';

    /** Khởi tạo workflow kiểm tra và đăng ký webhook Facebook. */
    public function __construct(private readonly FacebookAppConfig $config, private readonly FacebookSubscriptionClient $client) {}

    /** Bảo đảm App có subscription Page đúng callback và đầy đủ field bắt buộc. */
    public function ensureAppPageWebhook(): array
    {
        $this->config->ensureWebhookConfigured();
        if ($this->hasCurrentPageWebhook()) return ['success' => true, 'skipped' => true, 'callback_url' => $this->callbackUrl()];
        try { return $this->client->subscribe(self::FIELDS_WITH_ECHOES); }
        catch (\Throwable $e) { if (! str_contains($e->getMessage(), 'message_echoes')) throw $e; return $this->client->subscribe(self::FIELDS); }
    }

    /** Kiểm tra subscription hiện tại có khớp callback và field yêu cầu hay không. */
    public function hasCurrentPageWebhook(): bool
    {
        foreach ($this->pageSubscriptions() as $subscription) {
            if ((string) Arr::get($subscription, 'object') !== 'page') continue;
            $fields = collect((array) Arr::get($subscription, 'fields', []))
                ->map(fn ($field): string => is_array($field) ? (string) Arr::get($field, 'name') : (string) $field)->filter()->all();
            if ((string) Arr::get($subscription, 'callback_url') === $this->callbackUrl() && $this->required($fields)) return true;
        }
        return false;
    }

    /** Lấy danh sách Page subscription sau khi xác nhận cấu hình hợp lệ. */
    public function pageSubscriptions(): array { $this->config->ensureWebhookConfigured(); return $this->client->list(); }
    /** Trả về callback URL chuẩn dùng khi đối chiếu subscription. */
    public function callbackUrl(): string { return $this->config->callbackUrl(); }
    /** Kiểm tra subscription chứa đủ toàn bộ field bắt buộc. */
    private function required(array $fields): bool { return collect(explode(',', self::FIELDS_WITH_ECHOES))->every(fn (string $field): bool => in_array($field, $fields, true)); }
}
