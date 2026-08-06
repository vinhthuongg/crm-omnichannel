<?php

namespace Modules\Mobile\Http\Controllers;

use App\Services\ServerSentEventStream;
use Illuminate\Http\Request;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Message\Services\MessageStreamQueryService;
use Modules\Mobile\Presenters\MobileConversationPresenter;
use Modules\Shared\Http\Controllers\ApiController;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MobileConversationStreamController extends ApiController
{
    private const RELATIONS = ['sender', 'conversation.customer.channels', 'conversation.customer.tags', 'conversation.assignee', 'conversation.tags'];

    /** Nhận ConversationVisibilityService để kiểm tra phạm vi truy cập; MessageStreamQueryService để truy vấn dữ liệu; MobileConversationPresenter để định dạng dữ liệu đầu ra; ServerSentEventStream để ghi và flush sự kiện SSE đến client. */
    public function __construct(private readonly ConversationVisibilityService $visibility, private readonly MessageStreamQueryService $queries,
        private readonly MobileConversationPresenter $presenter, private readonly ServerSentEventStream $stream) {}

    /** Phát SSE các tin mới trong inbox mobile sau ID client đã nhận. */
    public function inbox(Request $request): StreamedResponse
    {
        return $this->response($request->integer('after_id'), fn (int $id) => $this->queries->inbox($request->user(), $id, self::RELATIONS));
    }

    /** Kiểm tra quyền rồi phát SSE các tin mới của hội thoại được chọn. */
    public function conversation(Request $request, Conversation $conversation): StreamedResponse
    {
        abort_unless($this->visibility->canView($request->user(), $conversation), 403);
        return $this->response($request->integer('after_id'), fn (int $id) => $this->queries->conversation($conversation, $id, self::RELATIONS));
    }

    /** Tạo SSE response, lấy message mới theo callback và serialize bằng presenter mobile. */
    private function response(int $afterId, callable $query): StreamedResponse
    {
        $lastId = max(0, $afterId);
        return $this->stream->response(function () use ($query, &$lastId): void {
            foreach ($query($lastId) as $message) {
                $lastId = max($lastId, (int) $message->id);
                $this->stream->write($lastId, ['message' => $this->presenter->message($message),
                    'conversation' => $message->conversation ? $this->presenter->conversation($message->conversation) : null]);
            }
        });
    }
}
