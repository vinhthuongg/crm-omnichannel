<?php

namespace Modules\Conversation\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Conversation\Actions\AssignConversationAction;
use Modules\Conversation\Actions\CloseConversationAction;
use Modules\Conversation\Actions\ListConversationsAction;
use Modules\Conversation\Actions\TagConversationAction;
use Modules\Conversation\Http\Requests\AssignConversationRequest;
use Modules\Conversation\Http\Requests\TagConversationRequest;
use Modules\Conversation\Http\Resources\ConversationResource;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Shared\Http\Controllers\ApiController;

class ConversationController extends ApiController
{
    /** Trả về danh sách hội thoại người dùng được xem sau khi áp dụng bộ lọc query và phân trang. */
    public function index(Request $request, ListConversationsAction $action)
    {
        return ConversationResource::collection($action->execute($request->user(), $request->query()));
    }

    /** Kiểm tra quyền rồi trả hội thoại cùng khách hàng, người phụ trách, tin nhắn và nhãn. */
    public function show(Request $request, Conversation $conversation, ConversationVisibilityService $visibility): ConversationResource
    {
        abort_unless($visibility->canView($request->user(), $conversation), 403);
        return new ConversationResource($conversation->load(['customer.channels', 'assignee', 'messages.sender', 'tags']));
    }

    /** Giao hội thoại cho người dùng được chỉ định và trả lỗi 409 khi vi phạm quy tắc phân công. */
    public function assign(AssignConversationRequest $request, Conversation $conversation, AssignConversationAction $action): ConversationResource
    {
        try {
            return new ConversationResource($action->execute($conversation, (int) $request->validated('assigned_to'), $request->user()));
        } catch (\RuntimeException $exception) {
            abort(409, $exception->getMessage());
        }
    }

    /** Chuyển hội thoại sang nhân viên khác và trả lỗi 409 nếu không thể chuyển. */
    public function transfer(AssignConversationRequest $request, Conversation $conversation, AssignConversationAction $action): ConversationResource
    {
        try {
            return new ConversationResource($action->transfer($conversation, (int) $request->validated('assigned_to'), $request->user()));
        } catch (\RuntimeException $exception) {
            abort(409, $exception->getMessage());
        }
    }

    /** Gỡ người phụ trách để đưa hội thoại về hàng chờ nếu người gọi có quyền phân công. */
    public function release(Request $request, Conversation $conversation, AssignConversationAction $action): ConversationResource
    {
        abort_unless($request->user()->can('conversation.transfer') || $request->user()->can('conversation.assign'), 403);

        try {
            return new ConversationResource($action->release($conversation, $request->user()));
        } catch (\RuntimeException $exception) {
            abort(409, $exception->getMessage());
        }
    }

    /** Đánh dấu hội thoại đã giải quyết nếu người gọi có quyền đóng hội thoại. */
    public function resolve(Request $request, Conversation $conversation, CloseConversationAction $action): ConversationResource
    {
        abort_unless($request->user()->can('conversation.close'), 403);

        try {
            return new ConversationResource($action->resolve($conversation, $request->user()));
        } catch (\RuntimeException $exception) {
            abort(409, $exception->getMessage());
        }
    }

    /** Đóng hội thoại, lưu mốc kết thúc và trả trạng thái mới cho API. */
    public function close(Request $request, Conversation $conversation, CloseConversationAction $action): ConversationResource
    {
        abort_unless($request->user()->can('conversation.close'), 403);
        try {
            return new ConversationResource($action->execute($conversation, $request->user()));
        } catch (\RuntimeException $exception) {
            abort(409, $exception->getMessage());
        }
    }

    /** Mở lại hội thoại đã đóng để tiếp tục xử lý tin nhắn. */
    public function reopen(Request $request, Conversation $conversation, CloseConversationAction $action): ConversationResource
    {
        abort_unless($request->user()->can('conversation.close'), 403);

        try {
            return new ConversationResource($action->reopen($conversation, $request->user()));
        } catch (\RuntimeException $exception) {
            abort(409, $exception->getMessage());
        }
    }

    /** Đồng bộ các nhãn hợp lệ lên hội thoại và trả dữ liệu hội thoại đã cập nhật. */
    public function tag(TagConversationRequest $request, Conversation $conversation, TagConversationAction $action): ConversationResource
    {
        return new ConversationResource($action->execute($conversation, $request->validated('tags'), $request->user()));
    }
}
