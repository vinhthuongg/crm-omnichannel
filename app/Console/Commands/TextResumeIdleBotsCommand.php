<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Text\Models\TextConversationLink;
use Modules\Text\Services\TextConversationBridge;

class TextResumeIdleBotsCommand extends Command
{
    protected $signature = 'text:resume-idle-bots {--dry-run : Show conversations that would be resumed}';

    protected $description = 'Return Facebook Messenger thread control to Text.com bot after CRM inactivity.';

    public function handle(TextConversationBridge $bridge): int
    {
        $links = TextConversationLink::query()
            ->with('conversation')
            ->whereNotNull('conversation_id')
            ->whereNotNull('bot_paused_at')
            ->whereNotNull('bot_resume_due_at')
            ->where('bot_resume_due_at', '<=', now())
            ->limit(100)
            ->get();

        if ($this->option('dry-run')) {
            $this->line('Idle bot links ready to resume: '.$links->count());
            $links->each(fn (TextConversationLink $link) => $this->line(
                "conversation={$link->conversation_id} chat={$link->text_chat_id} due={$link->bot_resume_due_at?->toDateTimeString()}"
            ));

            return self::SUCCESS;
        }

        $resumed = 0;

        foreach ($links as $link) {
            if ($bridge->resumeBotForLink($link)) {
                $resumed++;
            }
        }

        $this->info("Resumed {$resumed}/{$links->count()} idle Text.com bots.");

        return self::SUCCESS;
    }
}
