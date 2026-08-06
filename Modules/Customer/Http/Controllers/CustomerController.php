<?php

namespace Modules\Customer\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Customer\Actions\CreateCustomerAction;
use Modules\Customer\Actions\ListCustomersAction;
use Modules\Customer\Actions\UpdateCustomerAction;
use Modules\Customer\Http\Requests\StoreCustomerRequest;
use Modules\Customer\Http\Requests\UpdateCustomerRequest;
use Modules\Customer\Http\Resources\CustomerResource;
use Modules\Customer\Models\Customer;
use Modules\Shared\Http\Controllers\ApiController;

class CustomerController extends ApiController
{
    /** Kiểm tra quyền xem toàn bộ hội thoại rồi lọc và phân trang danh sách khách hàng. */
    public function index(Request $request, ListCustomersAction $action)
    {
        abort_unless($request->user()->can('conversation.view_all'), 403);
        return CustomerResource::collection($action->execute($request->query()));
    }

    /** Tạo khách hàng cùng kênh liên hệ từ request hợp lệ. */
    public function store(StoreCustomerRequest $request, CreateCustomerAction $action): CustomerResource
    {
        return new CustomerResource($action->execute($request->validated()));
    }

    /** Kiểm tra quyền rồi trả hồ sơ khách hàng cùng toàn bộ kênh liên hệ. */
    public function show(Request $request, Customer $customer): CustomerResource
    {
        abort_unless($request->user()->can('conversation.view_all'), 403);
        return new CustomerResource($customer->load('channels'));
    }

    /** Cập nhật hồ sơ/kênh liên hệ và trả CustomerResource mới nhất. */
    public function update(UpdateCustomerRequest $request, Customer $customer, UpdateCustomerAction $action): CustomerResource
    {
        return new CustomerResource($action->execute($customer, $request->validated()));
    }
}
