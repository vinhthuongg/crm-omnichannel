<?php

namespace Modules\Facebook\Services;

use Modules\Facebook\DTO\FacebookPageData;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class FacebookAvailablePageSession
{
    /** H?p nh?t danh s�ch Page m?i v?i Page d� luu trong session OAuth. */
    public function merge(array $configured): array
    {
        $pages = collect($configured)->keyBy(fn (FacebookPageData $page): string => $page->id);
        foreach ((array) session('facebook_available_pages', []) as $payload) {
            if (! is_array($payload) || empty($payload['page_id']) || empty($payload['page_access_token'])) continue;
            $page = $this->fromPayload($payload);
            $pages->put($page->id, $page);
        }
        return $pages->values()->all();
    }

    /** Lưu tạm danh sách Page khả dụng vào session hiện tại. */
    public function store(array $pages): void
    {
        session(['facebook_available_pages' => collect($pages)->mapWithKeys(fn (FacebookPageData $page): array => [$page->id => [
            'page_id' => $page->id, 'page_name' => $page->name, 'page_access_token' => $page->accessToken, 'page_avatar' => $page->avatar]])->all()]);
    }

    /** Lấy Page được chọn hoặc báo lỗi khi Page không khả dụng. */
    public function get(string $pageId): FacebookPageData
    {
        $payload = session('facebook_available_pages.'.$pageId);
        if (! is_array($payload)) throw new UnprocessableEntityHttpException('Fanpage is not available in current Facebook session.');
        return $this->fromPayload($payload);
    }

    /** Chuyển payload session thành DTO Facebook Page. */
    private function fromPayload(array $payload): FacebookPageData
    {
        return new FacebookPageData((string) $payload['page_id'], (string) ($payload['page_name'] ?? $payload['page_id']),
            (string) $payload['page_access_token'], $payload['page_avatar'] ?? null);
    }
}
