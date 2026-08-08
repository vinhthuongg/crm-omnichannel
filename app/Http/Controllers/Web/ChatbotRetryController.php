<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Message\Jobs\GenerateChatbotResponseJob;
use Modules\Message\Models\ChatbotResponse;

class ChatbotRetryController extends Controller
{
    public function __construct(private readonly ConversationVisibilityService $visibility) {}

    /** Kiểm tra quyền hội thoại rồi xếp lại một response chatbot đã lỗi mà không tạo câu trả lời thứ hai. */
    public function __invoke(Request $request, Conversation $conversation, ChatbotResponse $chatbotResponse): JsonResponse
    {
        abort_unless($this->visibility->canView($request->user(), $conversation), 403);
        abort_unless($chatbotResponse->conversation_id === $conversation->id, 404);
        abort_if($chatbotResponse->status === 'completed', 409, 'Câu trả lời đã hoàn tất.');

        $chatbotResponse->forceFill([
            'status' => 'pending',
            'error_code' => null,
            'error_message' => null,
        ])->save();
        GenerateChatbotResponseJob::dispatch($chatbotResponse->id);

        return response()->json(['data' => ['response_id' => $chatbotResponse->id, 'status' => 'pending']], 202);
    }
}
