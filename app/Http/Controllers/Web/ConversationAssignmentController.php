<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationService;
use Modules\Conversation\Services\ConversationVisibilityService;

class ConversationAssignmentController extends Controller
{
    /** Nhận dịch vụ thay đổi phân công và dịch vụ kiểm tra quyền xem hội thoại. */
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly ConversationVisibilityService $visibility,
    ) {
    }

    /** Cho phép người dùng tự nhận hội thoại đang chờ và trả thông tin phân công mới. */
    public function claim(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($this->visibility->canView($request->user(), $conversation), 403);

        try {
            $claimed = $this->conversations->claim($conversation, $request->user());
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => [
            'id' => (int) $claimed->id,
            'assigned_to' => (int) $claimed->assigned_to,
            'assignee_name' => $claimed->assignee?->name,
            'claimed_at' => $claimed->claimed_at?->toISOString(),
        ]]);
    }

    /** Giao hội thoại cho nhân viên được chọn và trả các quyền thao tác mới cho giao diện. */
    public function assign(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($request->user()->can('conversation.assign') || $request->user()->can('conversation.transfer'), 403);
        $data = $request->validate(['assigned_to' => ['required', 'integer', 'exists:users,id']]);

        try {
            $assigned = $this->conversations->assign($conversation, (int) $data['assigned_to'], $request->user());
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => [
            'id' => (int) $assigned->id,
            'assigned_to' => $assigned->assigned_to ? (int) $assigned->assigned_to : null,
            'assigned_by' => $assigned->assigned_by ? (int) $assigned->assigned_by : null,
            'assigned_type' => $assigned->assigned_type,
            'assignee_name' => $assigned->assignee?->name,
            'claimed_at' => $assigned->claimed_at?->toISOString(),
            'can_claim' => false,
            'can_reply' => $request->user()->can('conversation.view_all') || (int) $assigned->assigned_to === (int) $request->user()->id,
            'can_assign' => true,
            'assign_url' => route('crm.conversations.assign', $assigned),
            'claim_url' => route('crm.conversations.claim', $assigned),
        ]]);
    }
}
