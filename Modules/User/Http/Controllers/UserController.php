<?php

namespace Modules\User\Http\Controllers;

use App\Models\User;
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
    public function index(Request $request, ListUsersAction $action)
    {
        abort_unless($request->user()->can('user.manage'), 403);
        return UserResource::collection($action->execute($request->query()));
    }

    public function store(StoreUserRequest $request, CreateUserAction $action): UserResource
    {
        return new UserResource($action->execute($request->validated()));
    }

    public function show(Request $request, User $user): UserResource
    {
        abort_unless($request->user()->can('user.manage'), 403);
        return new UserResource($user->load('roles'));
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUserAction $action): UserResource
    {
        return new UserResource($action->execute($user, $request->validated()));
    }
}