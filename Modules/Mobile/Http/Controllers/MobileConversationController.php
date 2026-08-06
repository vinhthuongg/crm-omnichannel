<?php

namespace Modules\Mobile\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Conversation\Actions\AssignConversationAction;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Conversation\Services\MobileConversationQueryService;
use Modules\Message\Actions\SendMessageAction;
use Modules\Message\Models\Message;
use Modules\Message\Services\ConversationMessageQueryService;
use Modules\Mobile\Presenters\MobileConversationPresenter;
use Modules\Shared\Http\Controllers\ApiController;

class MobileConversationController extends ApiController
{
    /** Nhận dịch vụ kiểm tra quyền, truy vấn hội thoại/tin nhắn và presenter cho API mobile. */
    public function __construct(
        private readonly ConversationVisibilityService $visibility,
        private readonly MobileConversationPresenter $presenter,
        private readonly MobileConversationQueryService $queries,
        private readonly ConversationMessageQueryService $messages,
    ) {
    }

    /** Trả về danh sách hội thoại mobile đã lọc theo trạng thái, người phụ trách, chưa đọc và từ khóa. */
    public function conversations(Request $request): JsonResponse
    {
        $paginator = $this->queries->paginate($request->user(), ['status' => $request->string('status')->toString(),
            'assigned_to' => $request->integer('assigned_to'), 'unread' => $request->boolean('unread'), 'q' => $request->string('q')->toString()],
            $this->perPage($request, 20));

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Conversation $item): array => $this->presenter->conversation($item))->values(),
            'meta' => $this->pagination($paginator),
        ]);
    }

    /** Kiểm tra quyền rồi trả về chi tiết hội thoại cùng khách hàng, kênh, nhãn và người phụ trách. */
    public function conversation(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeAccess($request, $conversation);
        return response()->json(['data' => $this->presenter->conversation(
            $conversation->load(['customer.channels', 'customer.tags', 'assignee', 'tags'])
        )]);
    }

    /** Trả về một trang tin nhắn trước hoặc sau mốc ID trong hội thoại được phép xem. */
    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeAccess($request, $conversation);
        $paginator = $this->messages->paginate($conversation, $request->filled('before_id') ? $request->integer('before_id') : null,
            $request->filled('after_id') ? $request->integer('after_id') : null, $this->perPage($request, 30));

        return response()->json([
            'data' => $paginator->getCollection()->reverse()->map(fn (Message $message): array => $this->presenter->message($message))->values(),
            'meta' => $this->pagination($paginator),
        ]);
    }

    /** Kiểm tra quyền trả lời, xác thực nội dung/tệp và gửi tin qua kênh Facebook hoặc Zalo. */
    public function sendMessage(Request $request, Conversation $conversation, SendMessageAction $action): JsonResponse
    {
        $this->authorizeAccess($request, $conversation);
        abort_unless($request->user()->can('conversation.view_all')
            || ($request->user()->can('conversation.reply') && (int) $conversation->assigned_to === (int) $request->user()->id), 403);
        $data = $request->validate([
            'content' => ['required_without:attachments', 'nullable', 'string'],
            'message_type' => ['sometimes', 'string', 'max:32'], 'attachments' => ['sometimes', 'array'],
            'channel' => ['nullable', 'in:facebook,zalo'],
        ]);
        $data['channel'] ??= $conversation->facebook_page_id ? 'facebook' : 'zalo';
        return response()->json(['data' => $this->presenter->message(
            $action->execute($conversation, $request->user(), $data)->load('sender')
        )], 201);
    }

    /** Đánh dấu toàn bộ tin trong hội thoại đã đọc và trả về trạng thái hội thoại mới nhất. */
    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeAccess($request, $conversation);
        $conversation->markAsRead();
        return response()->json(['data' => $this->presenter->conversation(
            $conversation->fresh(['customer.channels', 'customer.tags', 'assignee', 'tags'])
        )]);
    }

    /** Giao hội thoại cho người dùng hợp lệ nếu người gọi có quyền phân công hoặc chuyển tiếp. */
    public function assign(Request $request, Conversation $conversation, AssignConversationAction $action): JsonResponse
    {
        abort_unless($request->user()->can('conversation.assign') || $request->user()->can('conversation.transfer'), 403);
        $data = $request->validate(['assigned_to' => ['required', 'integer', 'exists:users,id']]);
        try {
            $conversation = $action->execute($conversation, (int) $data['assigned_to'], $request->user());
        } catch (\RuntimeException $exception) {
            abort(409, $exception->getMessage());
        }
        return response()->json(['data' => $this->presenter->conversation(
            $conversation->load(['customer.channels', 'customer.tags', 'assignee', 'tags'])
        )]);
    }

    /** Từ chối request khi người dùng không thuộc phạm vi được xem hội thoại. */
    private function authorizeAccess(Request $request, Conversation $conversation): void
    {
        abort_unless($this->visibility->canView($request->user(), $conversation), 403);
    }

    /** Trả current/last page, per-page, total và cờ còn trang cho hội thoại hoặc tin nhắn. */
    private function pagination($paginator): array
    {
        return ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(),
            'total' => $paginator->total(), 'last_page' => $paginator->lastPage(), 'has_more' => $paginator->hasMorePages()];
    }

    /** Chuẩn hóa per_page trong khoảng 1–100 và dùng giá trị mặc định khi client không truyền. */
    private function perPage(Request $request, int $default): int
    {
        return min(max((int) $request->query('per_page', $default), 1), 100);
    }
}
