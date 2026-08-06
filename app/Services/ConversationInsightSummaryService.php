<?php

namespace App\Services;

use Modules\Conversation\Models\Conversation;

class ConversationInsightSummaryService
{
    /** Nhận ConversationInsightExtractor để trích xuất nhu cầu, xe và số điện thoại; ConversationInsightFormatter để định dạng dữ liệu đầu ra. */
    public function __construct(private readonly ConversationInsightExtractor $extractor, private readonly ConversationInsightFormatter $formatter) {}

    /** Tổng hợp insight hội thoại thành nội dung tóm tắt dễ đọc. */
    public function summarize(Conversation $conversation): array
    {
        $conversation->loadMissing(['customer', 'assignee']);
        $messages = $conversation->messages()->with('sender')->latest()->limit(40)->get()->reverse()->values();
        if ($messages->isEmpty()) return ['text' => 'Chưa có đủ nội dung để tóm tắt', 'facts' => ['phones' => []]];
        $facts = $this->extractor->extract($messages);
        $storedPhone = $conversation->customer?->phone;
        $latestPhone = $facts['phones'] !== [] ? $facts['phones'][array_key_last($facts['phones'])] : null;
        $phone = $storedPhone ?: $latestPhone;
        $name = trim((string) $conversation->customer?->name);
        $name = $name === '' || preg_match('/^\d+$/', $name) ? 'Khách hàng' : $name;
        return ['text' => $this->formatter->format($name, $facts['vehicle'], $facts['intents'], $facts['advisors'], $storedPhone, $latestPhone, $phone),
            'facts' => [...$facts, 'phone' => $phone, 'stored_phone' => $storedPhone, 'latest_phone' => $latestPhone]];
    }
}
