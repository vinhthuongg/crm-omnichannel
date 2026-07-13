<?php

namespace Modules\Botpress\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Modules\Botpress\Models\BotpressConversationLink;

class BotpressAdminController extends Controller
{
    public function launch(): RedirectResponse
    {
        abort_unless(request()->user()?->can('user.manage'), 403);

        $token = $this->shareToken();
        abort_if($token === '', 404, 'Chatbot admin token is not configured.');

        return redirect()->route('botpress.admin.public', ['token' => $token]);
    }

    public function index(string $token): View
    {
        $this->guardToken($token);

        return $this->view($token);
    }

    public function show(string $token, int $conversation): View
    {
        $this->guardToken($token);

        return $this->view($token, $conversation);
    }

    public function storeKnowledge(string $token, Request $request): RedirectResponse
    {
        $this->guardToken($token);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'source_url' => ['nullable', 'url', 'max:2048'],
            'content' => ['nullable', 'string', 'max:50000'],
        ]);

        abort_if(blank($validated['source_url'] ?? null) && blank($validated['content'] ?? null), 422, 'Cần nhập URL hoặc nội dung tài liệu.');

        $webhookUrl = trim((string) config('services.botpress.knowledge_webhook_url', ''));
        if ($webhookUrl === '') {
            return back()->with('error', 'Chưa cấu hình webhook nhận tài liệu từ xa.');
        }

        $secret = trim((string) config('services.botpress.knowledge_webhook_secret', ''));
        $payload = [
            'type' => 'knowledge_document_upsert',
            'title' => $validated['title'],
            'source_url' => $validated['source_url'] ?? null,
            'content' => $validated['content'] ?? null,
            'submitted_at' => now()->toIso8601String(),
            'source' => 'crm_chatbot_admin',
        ];

        try {
            $http = Http::timeout(20)->acceptJson();

            if ($secret !== '') {
                $http = $http->withHeaders(['X-CRM-Secret' => $secret]);
            }

            $response = $http->post($webhookUrl, $payload);

            if (! $response->successful()) {
                Log::warning('Chatbot knowledge webhook failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return back()->with('error', 'Chưa nhận được tài liệu. HTTP '.$response->status().': '.mb_substr($response->body(), 0, 300));
            }
        } catch (\Throwable $exception) {
            Log::warning('Chatbot knowledge webhook exception', [
                'error' => $exception->getMessage(),
            ]);

            return back()->with('error', 'Không gửi được tài liệu: '.$exception->getMessage());
        }

        return back()->with('status', 'Đã gửi tài liệu để xử lý.');
    }

    private function view(string $token, ?int $conversationId = null): View
    {
        $conversations = $this->conversations();
        $selectedConversation = $conversationId ? $this->selectedConversation($conversationId) : $conversations->first()?->conversation;

        return view('botpress.admin', [
            'token' => $token,
            'conversations' => $conversations,
            'selectedConversation' => $selectedConversation,
            'knowledgeBases' => $this->knowledgeBases(),
            'canUploadKnowledge' => filled(config('services.botpress.knowledge_webhook_url')),
        ]);
    }

    private function guardToken(string $token): void
    {
        $expected = $this->shareToken();

        abort_if($expected === '' || ! hash_equals($expected, $token), 404);
    }

    private function shareToken(): string
    {
        return trim((string) config('services.botpress.admin_token', ''));
    }

    private function conversations()
    {
        return BotpressConversationLink::query()
            ->with([
                'conversation.customer',
                'conversation.messages' => fn ($query) => $query->latest('id')->limit(1),
            ])
            ->latest('updated_at')
            ->limit(50)
            ->get();
    }

    private function selectedConversation(int $conversationId)
    {
        return BotpressConversationLink::query()
            ->where('conversation_id', $conversationId)
            ->with([
                'conversation.customer',
                'conversation.assignee',
                'conversation.tags',
                'conversation.messages' => fn ($query) => $query->latest('id')->limit(80),
            ])
            ->firstOrFail()
            ->conversation;
    }

    private function knowledgeBases(): array
    {
        return [
            [
                'name' => 'Kho tri thức chính',
                'status' => 'Đang sử dụng',
                'description' => 'Nguồn dữ liệu dùng để chatbot trả lời khách hàng.',
            ],
            [
                'name' => 'Tài liệu gửi từ CRM',
                'status' => filled(config('services.botpress.knowledge_webhook_url')) ? 'Sẵn sàng nhận tài liệu' : 'Chưa cấu hình nhận tài liệu',
                'description' => 'Có thể thêm URL hoặc dán nội dung tài liệu từ xa.',
            ],
        ];
    }
}
