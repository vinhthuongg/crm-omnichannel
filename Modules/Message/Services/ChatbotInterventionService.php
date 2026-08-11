<?php

namespace Modules\Message\Services;

use Modules\Conversation\Models\Conversation;
use Modules\Message\Events\ChatbotResponseFailed;
use Modules\Message\Models\ChatbotResponse;

class ChatbotInterventionService
{
    /** Hủy các lượt bot chưa hoàn tất khi nhân viên đã trực tiếp tiếp quản cuộc trò chuyện. */
    public function cancelPendingFor(Conversation $conversation): int
    {
        $responses = ChatbotResponse::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('status', ['pending', 'retryable', 'streaming'])
            ->get();

        foreach ($responses as $response) {
            $response->forceFill([
                'status' => 'cancelled',
                'error_code' => 'staff_intervened',
                'error_message' => 'Nhân viên đã tiếp quản cuộc trò chuyện.',
                'completed_at' => now(),
            ])->save();

            event(new ChatbotResponseFailed($conversation->id, $response->id, [
                'message' => 'Nhân viên đã tiếp quản cuộc trò chuyện.',
                'retryable' => false,
            ]));
        }

        return $responses->count();
    }
}
