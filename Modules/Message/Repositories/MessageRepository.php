<?php

namespace Modules\Message\Repositories;

use Modules\Message\Models\Message;

class MessageRepository
{
    public function create(array $data): Message
    {
        return Message::query()->create($data)->load(['conversation.customer.channels', 'sender']);
    }
}