<?php

namespace Modules\Conversation\Actions;

use App\Models\User;
use Modules\Conversation\Repositories\ConversationRepository;

class ListConversationsAction
{
    public function __construct(private readonly ConversationRepository $repository)
    {
    }

    public function execute(User $user, array $filters): mixed
    {
        return $this->repository->paginateFor($user, $filters);
    }
}