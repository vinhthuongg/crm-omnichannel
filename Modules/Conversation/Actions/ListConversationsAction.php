<?php

namespace Modules\Conversation\Actions;

use App\Models\User;
use Modules\Conversation\Repositories\ConversationRepository;

class ListConversationsAction
{
    /** Nhận ConversationRepository để đọc và lưu dữ liệu. */
    public function __construct(private readonly ConversationRepository $repository)
    {
    }

    /** Trả danh sách hội thoại người dùng được xem sau khi áp dụng bộ lọc và phân trang. */
    public function execute(User $user, array $filters): mixed
    {
        return $this->repository->paginateFor($user, $filters);
    }
}
