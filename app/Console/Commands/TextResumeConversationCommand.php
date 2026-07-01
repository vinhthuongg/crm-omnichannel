<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Conversation\Models\Conversation;
use Modules\Facebook\Services\FacebookThreadControlService;
use Modules\Text\Models\TextConversationLink;

class TextResumeConversationCommand extends Command
{
    protected $signature = 'text:resume-conversation {conversation_id? : CRM conversation id} {--latest : Resume the latest conversation}';

    protected $description = 'Force return one Facebook Messenger conversation to the bot receiver.';

    public function handle(FacebookThreadControlService $threadControl): int
    {
        $conversation = $this->resolveConversation();

        if (! $conversation) {
            $this->error('Conversation not found.');

            return self::FAILURE;
        }

        $this->line("Returning conversation {$conversation->id} to bot receiver...");

        if (! $threadControl->passThreadControlToBot($conversation, 'CRM manual bot resume')) {
            $this->error('Facebook pass_thread_control failed. Check storage/logs/laravel.log for details.');

            return self::FAILURE;
        }

        TextConversationLink::query()
            ->where('conversation_id', $conversation->id)
            ->update([
                'bot_paused_at' => null,
                'bot_resume_due_at' => null,
                'bot_resumed_at' => now(),
            ]);

        $this->info('Conversation returned to bot receiver.');

        return self::SUCCESS;
    }

    private function resolveConversation(): ?Conversation
    {
        if ($this->option('latest')) {
            return Conversation::query()
                ->whereNotNull('facebook_page_id')
                ->latest('last_message_at')
                ->first();
        }

        $conversationId = (int) $this->argument('conversation_id');

        if ($conversationId <= 0) {
            return null;
        }

        return Conversation::query()->find($conversationId);
    }
}
