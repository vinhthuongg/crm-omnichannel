<?php

namespace Modules\User\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Shared\Http\Controllers\ApiController;
use Modules\User\Actions\CreateUserAction;
use Modules\User\Actions\ListUsersAction;
use Modules\User\Actions\UpdateUserAction;
use Modules\User\Http\Requests\StoreUserRequest;
use Modules\User\Http\Requests\UpdateUserRequest;
use Modules\User\Http\Resources\UserResource;

class UserController extends ApiController
{
    /** Trả danh sách tài khoản đã phân trang theo từ khóa tìm kiếm. */
    public function index(Request $request, ListUsersAction $action)
    {
        abort_unless($request->user()->can('user.manage'), 403);
        return UserResource::collection($action->execute($request->query()));
    }

    /** Tạo tài khoản API từ dữ liệu hợp lệ và trả UserResource vừa tạo. */
    public function store(StoreUserRequest $request, CreateUserAction $action): UserResource
    {
        return new UserResource($action->execute($request->validated()));
    }

    /** Tạo tài khoản từ biểu mẫu web rồi quay lại kèm thông báo email đã tạo. */
    public function storeWeb(StoreUserRequest $request, CreateUserAction $action): RedirectResponse
    {
        $user = $action->execute($request->validated());

        return redirect()
            ->route('crm.agents')
            ->with('status', "Đã tạo tài khoản {$user->email}.");
    }

    /** Trả hồ sơ cùng vai trò của tài khoản được chọn. */
    public function show(Request $request, User $user): UserResource
    {
        abort_unless($request->user()->can('user.manage'), 403);
        return new UserResource($user->load('roles'));
    }

    /** Cập nhật hồ sơ, mật khẩu hoặc vai trò và trả UserResource mới nhất. */
    public function update(UpdateUserRequest $request, User $user, UpdateUserAction $action): UserResource
    {
        return new UserResource($action->execute($user, $request->validated()));
    }
}
