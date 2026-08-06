<?php

namespace Modules\Message\Repositories;

use Modules\Message\Models\Message;

class MessageRepository
{
    /** Lưu message mới và nạp sender cùng thông tin khách hàng của hội thoại. */
    public function create(array $data): Message
    {
        return Message::query()->create($data)->load(['conversation.customer.channels', 'sender']);
    }
}
