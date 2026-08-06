<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\ServerSentEventStream;
use Illuminate\Http\Request;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Message\Http\Resources\MessageResource;
use Modules\Message\Services\MessageStreamQueryService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessengerStreamController extends Controller
{
    /** Nhận ConversationVisibilityService để kiểm tra phạm vi truy cập; MessageStreamQueryService để truy vấn dữ liệu; ServerSentEventStream để ghi và flush sự kiện SSE đến client. */
    public function __construct(private readonly ConversationVisibilityService $visibility,
        private readonly MessageStreamQueryService $queries, private readonly ServerSentEventStream $stream) {}

    /** Mở SSE stream phát các tin mới của một hội thoại sau mốc message ID. */
    public function conversation(Request $request, Conversation $conversation): StreamedResponse
    {
        abort_unless($this->visibility->canView($request->user(), $conversation), 403);
        $lastId = max(0, $request->integer('after_id'));
        return $this->stream->response(function () use ($conversation, &$lastId): void {
            foreach ($this->queries->conversation($conversation, $lastId, ['sender']) as $message) {
                $lastId = max($lastId, (int) $message->id);
                $this->stream->write($lastId, (new MessageResource($message))->resolve());
            }
        });
    }

    /** Mở SSE stream phát thay đổi tin nhắn trong toàn bộ inbox người dùng được xem. */
    public function inbox(Request $request): StreamedResponse
    {
        $lastId = max(0, $request->integer('after_id'));
        $user = $request->user();
        return $this->stream->response(function () use ($user, &$lastId): void {
            foreach ($this->queries->inbox($user, $lastId, ['sender', 'conversation.customer', 'conversation.tags']) as $message) {
                $lastId = max($lastId, (int) $message->id);
                $this->stream->write($lastId, (new MessageResource($message))->resolve());
            }
        });
    }
}
