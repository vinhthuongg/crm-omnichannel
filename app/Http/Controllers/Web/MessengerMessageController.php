<?php

namespace App\Http\Controllers\Web;

use App\Actions\Web\SendMessengerMessageAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\SendMessengerMessageRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Message\Http\Resources\MessageResource;
use Modules\Message\Models\Message;
use Modules\Message\Services\MessengerAttachmentStorage;
use Modules\Message\Services\MessageMutationService;

class MessengerMessageController extends Controller
{
    /** Nhận dịch vụ kiểm tra quyền hội thoại và dịch vụ sửa/xóa tin nhắn. */
    public function __construct(private readonly ConversationVisibilityService $visibility, private readonly MessageMutationService $mutations)
    {
    }

    /** Gửi tin nhắn web qua kênh đã chọn và trả JSON hoặc redirect kèm lỗi thân thiện. */
    public function send(SendMessengerMessageRequest $request, Conversation $conversation, SendMessengerMessageAction $action): RedirectResponse|JsonResponse
    {
        $this->authorizeAccess($request, $conversation);
        try {
            $message = $action->execute($conversation, $request->user(), $request->validated());
        } catch (\Throwable $exception) {
            Log::warning('Web messenger message failed', ['conversation_id' => $conversation->id, 'channel' => $request->input('channel'), 'error' => $exception->getMessage()]);
            $error = $this->friendlyError($exception->getMessage());
            return $request->expectsJson()
                ? response()->json(['message' => $error], 422)
                : back()->withErrors(['content' => $error])->withInput();
        }

        return $request->expectsJson()
            ? response()->json(['data' => (new MessageResource($message->loadMissing(['sender', 'conversation.customer', 'conversation.tags'])))->resolve()], 201)
            : redirect()->route('crm.conversations.show', $conversation);
    }

    /** Kiểm tra quyền, lưu tối đa năm tệp đính kèm và trả metadata cho trình soạn tin. */
    public function upload(Request $request, Conversation $conversation, MessengerAttachmentStorage $storage): JsonResponse
    {
        $this->authorizeAccess($request, $conversation);
        $data = $request->validate(['attachments' => ['required', 'array', 'max:5'], 'attachments.*' => ['file', 'max:20480']]);
        $attachments = collect($data['attachments'])->map(fn ($file): array => $storage->store($file))->values()->all();
        return response()->json(['data' => $attachments], 201);
    }

    /** Hủy tin chưa gửi hoặc đánh dấu thu hồi nội bộ, đồng thời báo giới hạn thu hồi của Facebook. */
    public function recall(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        $this->authorizeMessage($request, $conversation, $message);
        ['message' => $message, 'cancelled' => $cancelled] = $this->mutations->recall($message, $request->user());

        return response()->json([
            'data' => (new MessageResource($message))->resolve(),
            'meta' => [
                'facebook_recalled' => false,
                'facebook_cancelled_before_send' => $cancelled,
                'message' => $cancelled ? 'Tin nhan da duoc huy truoc khi gui sang Facebook.' : 'Messenger Platform khong ho tro thu hoi tin da gui tren Facebook qua API.',
            ],
        ]);
    }

    /** Xóa một tin thuộc hội thoại sau khi kiểm tra quyền và quan hệ message–conversation. */
    public function destroy(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        $this->authorizeMessage($request, $conversation, $message);
        $messageId = $this->mutations->delete($conversation, $message, $request->user());
        return response()->json(['data' => ['conversation_id' => (int) $conversation->id, 'message_ids' => [$messageId]]]);
    }

    /** Xóa toàn bộ lịch sử tin nhắn của hội thoại nhưng giữ lại bản ghi hội thoại. */
    public function clear(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeAccess($request, $conversation);
        $this->mutations->clear($conversation, $request->user());
        return response()->json(['data' => ['conversation_id' => (int) $conversation->id, 'message_ids' => [], 'clear_all' => true]]);
    }

    /** Xóa hội thoại cùng dữ liệu tin nhắn liên quan và trả ID để giao diện loại khỏi inbox. */
    public function destroyConversation(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeAccess($request, $conversation);
        $id = $this->mutations->deleteConversation($conversation);
        return response()->json(['data' => ['conversation_id' => $id, 'message_ids' => [], 'clear_all' => true, 'delete_conversation' => true]]);
    }

    /** Từ chối request nếu người dùng không được xem hoặc thao tác tin nhắn của hội thoại. */
    private function authorizeAccess(Request $request, Conversation $conversation): void
    {
        abort_unless($this->visibility->canView($request->user(), $conversation), 403);
    }

    /** Kiểm tra quyền xem hội thoại và bảo đảm tin nhắn thực sự thuộc hội thoại trên URL. */
    private function authorizeMessage(Request $request, Conversation $conversation, Message $message): void
    {
        $this->authorizeAccess($request, $conversation);
        abort_unless($message->conversation_id === $conversation->id, 404);
    }

    /** Chuyển lỗi kỹ thuật của kênh gửi thành thông báo có thể hiển thị cho người dùng. */
    private function friendlyError(string $error): string
    {
        if (str_contains($error, 'does not have')) return 'Khach hang chua co external id cho kenh nay.';
        if (str_contains($error, 'Unsupported message channel')) return 'Kenh gui tin nhan khong duoc ho tro.';
        return 'Khong gui duoc tin nhan: '.$error;
    }
}
