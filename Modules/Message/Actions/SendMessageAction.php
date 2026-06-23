<?php

namespace Modules\Message\Actions;

use App\Models\User;
use Modules\Conversation\Models\Conversation;
use Modules\Message\Models\Message;
use Modules\Message\Services\MessageService;

class SendMessageAction
{
    public function __construct(private readonly MessageService $service)
    {
    }

    public function execute(Conversation $conversation, User $user, array $data): Message
    {
        return $this->service->sendFromUser($conversation, $user, $data);
    }
}