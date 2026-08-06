<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\Tag;
use Modules\Conversation\Services\ConversationService;
use Modules\Conversation\Services\ConversationTagCatalogService;
use Modules\Conversation\Services\ConversationTagManagementService;
use Modules\Conversation\Services\ConversationVisibilityService;

class ConversationTagController extends Controller
{
    /** Nhận các dịch vụ kiểm tra quyền, đồng bộ nhãn, đọc danh mục và quản lý nhãn. */
    public function __construct(
        private readonly ConversationVisibilityService $visibility,
        private readonly ConversationService $conversations,
        private readonly ConversationTagCatalogService $catalog,
        private readonly ConversationTagManagementService $management,
    ) {
    }

    /** Trả toàn bộ nhãn hội thoại mặc định nếu người dùng có quyền quản lý nhãn. */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeManagement($request);
        return response()->json(['data' => $this->catalog->payloads()]);
    }

    /** Từ chối tạo nhãn tùy ý và trả lại danh sách nhãn mặc định được hệ thống cho phép. */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeManagement($request);
        Tag::ensureDefaults();

        return response()->json([
            'message' => 'Hệ thống chỉ cho phép dùng các nhãn mặc định.',
            'data' => ['tags' => $this->catalog->payloads()],
        ], 422);
    }

    /** Cập nhật tên và màu của nhãn, sau đó trả nhãn cùng danh mục mới nhất. */
    public function update(Request $request, Tag $tag): JsonResponse
    {
        $this->authorizeManagement($request);
        $validated = $request->validate(['name' => ['required', 'string', 'max:80'], 'color' => ['nullable', 'string', 'max:24']]);
        $tag = $this->management->update($tag, $validated);

        return response()->json(['data' => ['tag' => $this->catalog->payload($tag->refresh()), 'tags' => $this->catalog->payloads()]]);
    }

    /** Xóa nhãn được phép xóa và trả danh mục nhãn còn lại. */
    public function destroy(Request $request, Tag $tag): JsonResponse
    {
        $this->authorizeManagement($request);
        $this->management->delete($tag);

        return response()->json(['data' => ['tags' => $this->catalog->payloads()]]);
    }

    /** Kiểm tra quyền rồi đồng bộ tối đa một nhãn lên hội thoại được chọn. */
    public function sync(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($this->visibility->canView($request->user(), $conversation), 403);
        $this->authorizeManagement($request);
        $validated = $request->validate([
            'tags' => ['array'],
            'tags.*.name' => ['required', 'string', 'max:80'],
            'tags.*.color' => ['nullable', 'string', 'max:24'],
        ]);
        $conversation = $this->conversations->syncTags($conversation, array_slice($validated['tags'] ?? [], 0, 1), $request->user());

        return response()->json(['data' => [
            'id' => (int) $conversation->id,
            'tags' => $conversation->tags->map(fn (Tag $tag): array => [
                'id' => (int) $tag->id, 'name' => $tag->name, 'color' => $tag->color,
            ])->values()->all(),
        ]]);
    }

    /** Chỉ cho phép người có quyền gắn nhãn, xem toàn bộ hoặc vai trò Admin quản lý nhãn. */
    private function authorizeManagement(Request $request): void
    {
        abort_unless($request->user()->can('conversation.tag')
            || $request->user()->can('conversation.view_all')
            || $request->user()->hasRole('Admin'), 403);
    }

}
