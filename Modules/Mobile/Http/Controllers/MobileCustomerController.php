<?php

namespace Modules\Mobile\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Conversation\Models\Tag;
use Modules\Conversation\Services\ConversationTagCatalogService;
use Modules\Customer\Models\Customer;
use Modules\Customer\Services\CustomerAccessService;
use Modules\Customer\Services\CustomerProfileService;
use Modules\Mobile\Presenters\MobileCustomerPresenter;
use Modules\Shared\Http\Controllers\ApiController;

class MobileCustomerController extends ApiController
{
    /** Nhận dịch vụ kiểm soát truy cập, cập nhật hồ sơ, presenter và danh mục nhãn khách hàng. */
    public function __construct(private readonly CustomerAccessService $access, private readonly CustomerProfileService $profiles,
        private readonly MobileCustomerPresenter $presenter, private readonly ConversationTagCatalogService $tags) {}

    /** Trả danh sách khách hàng được phép xem, lọc theo từ khóa và trạng thái tiềm năng. */
    public function index(Request $request): JsonResponse
    {
        $page = $this->access->paginate($request->user(), $request->string('q')->trim()->toString(),
            $request->has('potential') ? $request->boolean('potential') : null, $request->integer('per_page', 20));
        return response()->json(['data' => $page->getCollection()->map(fn (Customer $customer): array => $this->presenter->summary($customer))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'has_more' => $page->hasMorePages()]]);
    }

    /** Kiểm tra quyền rồi trả hồ sơ đầy đủ của khách hàng cho ứng dụng mobile. */
    public function show(Request $request, Customer $customer): JsonResponse
    {
        return response()->json(['data' => $this->presenter->detail($this->access->accessible($request->user(), $customer))]);
    }

    /** Đánh dấu khách hàng là tiềm năng và ghi nhận người thực hiện thay đổi. */
    public function markPotential(Request $request, Customer $customer): JsonResponse
    {
        return response()->json(['data' => $this->presenter->detail($this->profiles->markPotential($request->user(), $customer, true))]);
    }

    /** Bỏ trạng thái tiềm năng của khách hàng và trả hồ sơ mới nhất. */
    public function unmarkPotential(Request $request, Customer $customer): JsonResponse
    {
        return response()->json(['data' => $this->presenter->detail($this->profiles->markPotential($request->user(), $customer, false))]);
    }

    /** Trả danh sách nhãn khách hàng mặc định để mobile hiển thị lựa chọn. */
    public function tags(): JsonResponse
    {
        return response()->json(['data' => $this->tags->defaults()
            ->map(fn (Tag $tag): array => $this->presenter->tag($tag))->values()]);
    }

    /** Gắn nhãn tồn tại vào khách hàng sau khi kiểm tra quyền truy cập hồ sơ. */
    public function attachTag(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate(['tag_id' => ['required', 'integer', 'exists:tags,id']]);
        return response()->json(['data' => $this->presenter->detail($this->profiles->attachTag($request->user(), $customer, (int) $data['tag_id']))]);
    }

    /** Gỡ nhãn được chỉ định khỏi khách hàng và trả hồ sơ đã cập nhật. */
    public function detachTag(Request $request, Customer $customer, int $tagId): JsonResponse
    {
        return response()->json(['data' => $this->presenter->detail($this->profiles->detachTag($request->user(), $customer, $tagId))]);
    }
}
