<?php

namespace Modules\Facebook\Services;

use App\Models\ApplicationSetting;
use Illuminate\Support\Facades\Cache;

class FacebookFirstContactMenuSettings
{
    private const KEY = 'facebook.first_contact_menu';

    /** Đọc cấu hình menu trong database và dùng cấu hình mặc định nếu Admin chưa tùy chỉnh. */
    public function get(): array
    {
        return Cache::remember(self::KEY, now()->addMinutes(10), function (): array {
            $stored = ApplicationSetting::query()->find(self::KEY)?->value;
            $defaults = (array) config('services.facebook.first_contact_menu', []);
            $data = is_array($stored) ? array_replace($defaults, $stored) : $defaults;

            return $this->normalize($data);
        });
    }

    /** Lưu menu đã kiểm tra từ trang Admin và xóa cache để áp dụng ngay cho tin tiếp theo. */
    public function update(array $data): array
    {
        $value = $this->normalize($data);
        ApplicationSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => $value]);
        Cache::forget(self::KEY);

        return $value;
    }

    /** Giữ cấu trúc menu ổn định, tối đa ba thẻ và hai nút cho mỗi thẻ trong giao diện hiện tại. */
    private function normalize(array $data): array
    {
        return [
            'enabled' => filter_var($data['enabled'] ?? false, FILTER_VALIDATE_BOOL),
            'text' => trim((string) ($data['text'] ?? '')),
            'phone_enabled' => filter_var($data['phone_enabled'] ?? true, FILTER_VALIDATE_BOOL),
            'phone_text' => trim((string) ($data['phone_text'] ?? '')),
            'elements' => collect((array) ($data['elements'] ?? []))->take(3)->map(fn (array $element): array => [
                'title' => trim((string) ($element['title'] ?? '')),
                'subtitle' => trim((string) ($element['subtitle'] ?? '')),
                'image_url' => trim((string) ($element['image_url'] ?? '')),
                'buttons' => collect((array) ($element['buttons'] ?? []))->take(2)->map(fn (array $button): array => [
                    'title' => trim((string) ($button['title'] ?? '')),
                    'payload' => trim((string) ($button['payload'] ?? '')),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
