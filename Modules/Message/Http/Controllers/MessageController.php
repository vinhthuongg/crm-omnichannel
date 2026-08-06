<?php

namespace Modules\Message\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Conversation\Models\Conversation;
use Modules\Message\Actions\SendMessageAction;
use Modules\Message\Http\Requests\SendMessageRequest;
use Modules\Message\Http\Resources\MessageResource;
use Modules\Message\Services\MessageQueryService;
use Modules\Shared\Http\Controllers\ApiController;

class MessageController extends ApiController
{
    /** Nhận dịch vụ truy vấn các tin nhắn mà người dùng có quyền xem trong hội thoại. */
    public function __construct(private readonly MessageQueryService $queries) {}

    /** Phân trang các tin nhắn người dùng được phép xem trong hội thoại được chọn. */
    public function index(Request $request, Conversation $conversation)
    {
        return MessageResource::collection($this->queries->paginate($request->user(), $conversation, $request->integer('per_page', 50)));
    }

    /** Kiểm tra quyền trả lời rồi lưu và xếp hàng gửi message mới của người dùng. */
    public function store(SendMessageRequest $request, Conversation $conversation, SendMessageAction $action, \Modules\Conversation\Services\ConversationVisibilityService $visibility): MessageResource
    {
        abort_unless($visibility->canView($request->user(), $conversation), 403);
        return new MessageResource($action->execute($conversation, $request->user(), $request->validated()));
    }
}
