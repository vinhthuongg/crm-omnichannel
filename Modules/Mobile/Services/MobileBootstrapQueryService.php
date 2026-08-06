<?php

namespace Modules\Mobile\Services;

use App\Models\User;
use Modules\Conversation\Services\ConversationTagCatalogService;

class MobileBootstrapQueryService
{
    /** Nhận ConversationTagCatalogService để cung cấp danh mục nhãn mặc định. */
    public function __construct(private readonly ConversationTagCatalogService $tags) {}

    /** Tổng hợp dữ liệu bootstrap cần thiết khi ứng dụng mobile khởi động. */
    public function data(): array
    {
        return ['agents' => User::query()->where('is_active', true)->with('roles')->orderBy('name')->get(),
            'tags' => $this->tags->defaults()];
    }
}
