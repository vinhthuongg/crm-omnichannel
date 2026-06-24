<?php

namespace Modules\Facebook\Actions;

use App\Models\User;
use Modules\Facebook\Models\FacebookPage;
use Modules\Facebook\Services\FacebookConversationImportService;

class ImportFacebookPageMessagesAction
{
    public function __construct(private readonly FacebookConversationImportService $importer)
    {
    }

    public function execute(User $user, FacebookPage $page, int $conversationLimit = 20): array
    {
        abort_unless($page->user_id === $user->id || $user->can('conversation.view_all'), 403);

        return $this->importer->importPage($page, $conversationLimit);
    }
}
