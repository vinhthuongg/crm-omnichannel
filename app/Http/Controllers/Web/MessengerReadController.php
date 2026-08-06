<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\ConversationReplySuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Message\Http\Resources\MessageResource;
use Modules\Message\Services\ConversationMessageQueryService;

class MessengerReadController extends Controller
{
    /** Nhận ConversationVisibilityService để kiểm tra phạm vi truy cập; ConversationMessageQueryService để truy vấn dữ liệu. */
    public function __construct(private readonly ConversationVisibilityService $visibility, private readonly ConversationMessageQueryService $messages)
    {
    }

    /** Trả các tin nhắn quanh mốc before/after ID sau khi kiểm tra quyền xem hội thoại. */
    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeAccess($request, $conversation);
        $result = $this->messages->window($conversation, $request->integer('before_id'), $request->integer('after_id'), $request->integer('limit', 10));
        return response()->json([
            'data' => MessageResource::collection($result['messages'])->resolve(),
            'meta' => ['has_more' => $result['has_more'], 'oldest_id' => $result['oldest_id']],
        ]);
    }

    /** Trả gợi ý phản hồi đã cache hoặc xếp job tạo mới từ tin gần nhất của khách hàng. */
    public function suggestions(Request $request, Conversation $conversation, ConversationReplySuggestionService $suggestions): JsonResponse
    {
        $this->authorizeAccess($request, $conversation);
        $messageId = $suggestions->latestMessageId($conversation);
        $cached = $suggestions->cached($conversation, $messageId);
        $refresh = $request->boolean('refresh');
        if ($refresh || (! $cached && $suggestions->hasProvider())) {
            $suggestions->queue($conversation, $messageId, $refresh);
        }

        return response()->json(['data' => [
            'suggestions' => $cached ? (array) $cached->suggestions : [],
            'provider' => $cached?->provider ?? 'local',
            'cached' => (bool) $cached,
            'pending' => ! $cached && (bool) $messageId && $suggestions->hasProvider(),
            'message_id' => $messageId,
        ]]);
    }

    /** Đánh dấu hội thoại đã đọc và trả bộ đếm chưa đọc mới nhất cho giao diện. */
    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeAccess($request, $conversation);
        if ((int) $conversation->unread_messages_count > 0) {
            $conversation->markAsRead();
        }

        return response()->json(['data' => [
            'id' => (int) $conversation->id,
            'unread_messages_count' => (int) $conversation->unread_messages_count,
            'is_unread' => false,
        ]]);
    }

    /** Từ chối request nếu người dùng không được xem hội thoại cần đánh dấu đã đọc. */
    private function authorizeAccess(Request $request, Conversation $conversation): void
    {
        abort_unless($this->visibility->canView($request->user(), $conversation), 403);
    }
}
