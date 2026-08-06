<?php

namespace Modules\Message\Actions;

use App\Models\User;
use Modules\Conversation\Models\Conversation;
use Modules\Message\Models\Message;
use Modules\Message\Services\MessageService;

class SendMessageAction
{
    /** Nhận MessageService để ghi nhận và gửi tin nhắn trong hội thoại. */
    public function __construct(private readonly MessageService $service)
    {
    }

    /** Lưu tin do người dùng gửi và xếp hàng chuyển sang kênh ngoài. */
    public function execute(Conversation $conversation, User $user, array $data): Message
    {
        return $this->service->sendFromUser($conversation, $user, $data);
    }
}
