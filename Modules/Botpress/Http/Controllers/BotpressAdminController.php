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
        abort_if($token === '', 404, 'Botpress admin token is not configured.');

        return redirect()->route('botpress.admin.public', ['token' => $token]);
    }

    public function index(string $token): View
    {
        $this->guardToken($token);

        return view('botpress.admin', [
            'token' => $token,
            'studioUrl' => trim((string) config('services.botpress.studio_url', '')),
            'integration' => $this->integrationStatus(),
            'conversations' => $this->conversations(),
            'knowledgeBases' => $this->knowledgeBases(),
            'canUploadKnowledge' => filled(config('services.botpress.knowledge_webhook_url')),
        ]);
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
            return back()->with('error', 'Chưa cấu hình BOTPRESS_KNOWLEDGE_WEBHOOK_URL để nhận tài liệu từ xa.');
        }

        $secret = trim((string) config('services.botpress.knowledge_webhook_secret', ''));
        $payload = [
            'type' => 'knowledge_document_upsert',
            'title' => $validated['title'],
            'source_url' => $validated['source_url'] ?? null,
            'content' => $validated['content'] ?? null,
            'submitted_at' => now()->toIso8601String(),
            'source' => 'crm_botpress_admin',
        ];

        try {
            $request = Http::timeout(20)->acceptJson();

            if ($secret !== '') {
                $request = $request->withHeaders([
                    'X-CRM-Secret' => $secret,
                ]);
            }

            $response = $request->post($webhookUrl, $payload);

            if (! $response->successful()) {
                Log::warning('Botpress knowledge webhook failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return back()->with('error', 'Botpress chưa nhận tài liệu. HTTP '.$response->status().': '.mb_substr($response->body(), 0, 300));
            }
        } catch (\Throwable $exception) {
            Log::warning('Botpress knowledge webhook exception', [
                'error' => $exception->getMessage(),
            ]);

            return back()->with('error', 'Không gửi được tài liệu sang Botpress: '.$exception->getMessage());
        }

        return back()->with('status', 'Đã gửi tài liệu sang Botpress để xử lý.');
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

    private function integrationStatus(): array
    {
        return [
            'enabled' => (bool) config('services.botpress.enabled', false),
            'base_url' => (string) config('services.botpress.base_url', ''),
            'webhook_id' => (string) config('services.botpress.webhook_id', ''),
            'has_api_key' => filled(config('services.botpress.api_key')),
            'prefer_callback' => (bool) config('services.botpress.prefer_callback', true),
        ];
    }

    private function conversations()
    {
        return BotpressConversationLink::query()
            ->with([
                'conversation.customer',
                'conversation.messages' => fn ($query) => $query->latest('id')->limit(6),
            ])
            ->latest('updated_at')
            ->limit(50)
            ->get();
    }

    private function knowledgeBases(): array
    {
        $webhookId = trim((string) config('services.botpress.webhook_id', ''));

        return [
            [
                'name' => 'Botpress Knowledge Bases',
                'status' => 'Cấu hình trong Botpress',
                'description' => $webhookId !== ''
                    ? 'Webhook Chat ID: '.$webhookId
                    : 'Chưa cấu hình BOTPRESS_WEBHOOK_ID.',
            ],
            [
                'name' => 'Tài liệu gửi từ CRM',
                'status' => filled(config('services.botpress.knowledge_webhook_url')) ? 'Sẵn sàng nhận tài liệu' : 'Chưa cấu hình webhook nhận tài liệu',
                'description' => 'Form bên dưới gửi URL hoặc nội dung tài liệu sang Botpress qua webhook riêng.',
            ],
        ];
    }
}
