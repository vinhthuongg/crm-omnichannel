<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\View\View;
use Modules\Conversation\Models\Conversation;
use Modules\Message\Models\Message;

class ChatWebviewController extends Controller
{
    public function index(string $token): View
    {
        $this->guardToken($token);

        $conversations = $this->conversations();
        $selectedConversation = $conversations->first();

        if ($selectedConversation) {
            $selectedConversation = $this->selectedConversation((int) $selectedConversation->id);
        }

        return $this->view($token, $conversations, $selectedConversation);
    }

    public function show(string $token, Conversation $conversation): View
    {
        $this->guardToken($token);

        return $this->view($token, $this->conversations(), $this->selectedConversation((int) $conversation->id));
    }

    private function view(string $token, $conversations, ?Conversation $selectedConversation): View
    {
        return view('chat_webview.index', [
            'token' => $token,
            'conversations' => $conversations,
            'selectedConversation' => $selectedConversation,
            'messages' => $selectedConversation?->messages?->sortBy('id')->values() ?? collect(),
        ]);
    }

    private function guardToken(string $token): void
    {
        $expected = trim((string) config('services.chat_webview.token', ''));

        abort_if($expected === '' || ! hash_equals($expected, $token), 404);
    }

    private function conversations()
    {
        return Conversation::query()
            ->with([
                'customer.tags',
                'assignee',
                'tags',
                'messages' => fn ($query) => $query->latest('id')->limit(1),
            ])
            ->latest('last_message_at')
            ->latest('id')
            ->limit(80)
            ->get();
    }

    private function selectedConversation(int $conversationId): Conversation
    {
        return Conversation::query()
            ->with([
                'customer.tags',
                'assignee',
                'tags',
                'messages' => fn ($query) => $query->latest('id')->limit(120),
            ])
            ->findOrFail($conversationId);
    }
}
