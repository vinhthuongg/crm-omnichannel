<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Auth\Actions\ChangePasswordAction;
use Modules\Auth\Actions\LoginAction;
use Modules\Auth\Actions\LogoutAction;
use Modules\Auth\Actions\RefreshTokenAction;
use Modules\Auth\DTO\LoginData;
use Modules\Auth\Http\Requests\ChangePasswordRequest;
use Modules\Auth\Http\Requests\LoginRequest;
use Modules\User\Http\Resources\UserResource;
use Modules\Shared\Http\Controllers\ApiController;

class AuthController extends ApiController
{
    public function login(LoginRequest $request, LoginAction $action): JsonResponse
    {
        $payload = $action->execute(new LoginData($request->string('email')->toString(), $request->string('password')->toString(), $request->string('device_name', 'api')->toString()));

        return response()->json(['data' => ['token_type' => $payload['token_type'], 'access_token' => $payload['access_token'], 'user' => new UserResource($payload['user'])]]);
    }

    public function profile(Request $request): UserResource
    {
        return new UserResource($request->user()->load('roles'));
    }

    public function refresh(Request $request, RefreshTokenAction $action): JsonResponse
    {
        $payload = $action->execute($request->user(), $request->string('device_name', 'api')->toString());

        return response()->json(['data' => ['token_type' => $payload['token_type'], 'access_token' => $payload['access_token'], 'user' => new UserResource($payload['user'])]]);
    }

    public function logout(Request $request, LogoutAction $action): JsonResponse
    {
        $action->execute($request->user());

        return response()->json(status: 204);
    }

    public function changePassword(ChangePasswordRequest $request, ChangePasswordAction $action): JsonResponse
    {
        $action->execute($request->user(), $request->string('password')->toString());

        return response()->json(status: 204);
    }
}