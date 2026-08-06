<?php

namespace App\Http\Controllers\Web;

use App\Actions\Web\GetMessengerViewDataAction;
use App\Http\Controllers\Controller;
use App\Services\MessengerConversationPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Conversation\Models\Conversation;
use Modules\Message\Http\Resources\MessageResource;
use Modules\Message\Services\ConversationMessageQueryService;

class MessengerController extends Controller
{
    /** Nhận presenter tạo payload Messenger và dịch vụ tải cửa sổ tin nhắn của hội thoại. */
    public function __construct(private readonly MessengerConversationPresenter $presenter, private readonly ConversationMessageQueryService $messages)
    {
    }

    /** Hiển thị hộp thư Messenger với danh sách hội thoại theo các bộ lọc trên request. */
    public function index(Request $request, GetMessengerViewDataAction $action): View
    {
        return view('messenger.index', $action->execute($request->user(), null, $this->filters($request)));
    }

    /** Mở, đánh dấu đã đọc và trả trang hoặc JSON chi tiết của một hội thoại Messenger. */
    public function show(Request $request, Conversation $conversation, GetMessengerViewDataAction $action): View|JsonResponse
    {
        $data = $action->execute($request->user(), $conversation, $this->filters($request));
        $this->markActiveConversationRead($data);

        if ($request->expectsJson()) {
            $result = $this->messages->window($conversation, 0, 0, 10);

            return response()->json($this->presenter->detail(
                $data['activeConversation'],
                MessageResource::collection($result['messages'])->resolve(), $result['has_more'],
                $data['activeChannel'],
                $request->user(),
            ));
        }

        return view('messenger.index', $data);
    }

    /** Chuẩn hóa các bộ lọc được phép từ dữ liệu đầu vào. */
    private function filters(Request $request): array
    {
        return [
            'search' => $request->string('q')->toString(),
            'tag' => $request->string('tag')->toString(),
            'channel' => $request->string('channel')->toString(),
            'status' => $request->string('status')->toString(),
        ];
    }

    /** Đánh dấu hội thoại đang mở là đã đọc trước khi render trang Messenger. */
    private function markActiveConversationRead(array &$data): void
    {
        $active = $data['activeConversation'] ?? null;
        if (! $active instanceof Conversation) {
            return;
        }
        if ((int) $active->unread_messages_count > 0) {
            $active->markAsRead();
        }
        $active->refresh();
        $data['activeConversation'] = $active;
        $data['conversations'] = $data['conversations']->map(function (Conversation $conversation) use ($active): Conversation {
            if ((int) $conversation->id === (int) $active->id) {
                $conversation->forceFill([
                    'unread_messages_count' => $active->unread_messages_count,
                    'last_read_at' => $active->last_read_at,
                ]);
            }
            return $conversation;
        });
    }
}
