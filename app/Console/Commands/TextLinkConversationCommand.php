<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Conversation\Models\Conversation;
use Modules\Text\Models\TextConversationLink;

class TextLinkConversationCommand extends Command
{
    protected $signature = 'text:link-conversation {conversation_id} {text_chat_id} {--thread_id=}';

    protected $description = 'Manually link a CRM conversation to a Text.com chat.';

    public function handle(): int
    {
        $conversation = Conversation::query()->find((int) $this->argument('conversation_id'));

        if (! $conversation) {
            $this->error('Conversation not found.');

            return self::FAILURE;
        }

        TextConversationLink::query()->updateOrCreate(
            ['text_chat_id' => (string) $this->argument('text_chat_id')],
            [
                'conversation_id' => $conversation->id,
                'text_thread_id' => (string) $this->option('thread_id') ?: null,
                'facebook_page_id' => $conversation->facebook_page_id,
                'facebook_psid' => $conversation->customer?->channels()
                    ->where('channel', 'facebook')
                    ->value('external_id'),
            ],
        );

        $this->info('Text.com chat linked to conversation.');

        return self::SUCCESS;
    }
}
