<?php

namespace Modules\Auth\Services;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\DTO\LoginData;

class AuthService
{
    /** Xác thực thông tin đăng nhập và tạo phiên hoặc token cho người dùng. */
    public function login(LoginData $data): array
    {
        $user = User::query()->where('email', $data->email)->first();

        if (! $user || ! Hash::check($data->password, $user->password) || ! $user->is_active) {
            throw new AuthenticationException('Invalid credentials.');
        }

        return [
            'token_type' => 'Bearer',
            'access_token' => $user->createToken($data->deviceName)->plainTextToken,
            'user' => $user->load('roles'),
        ];
    }

    /** Làm mới token xác thực cho phiên người dùng hiện tại. */
    public function refresh(User $user, string $deviceName): array
    {
        $user->currentAccessToken()?->delete();

        return ['token_type' => 'Bearer', 'access_token' => $user->createToken($deviceName)->plainTextToken, 'user' => $user->load('roles')];
    }

    /** Thu hồi phiên hoặc token đăng nhập hiện tại của người dùng. */
    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }

    /** Xác minh và cập nhật mật khẩu mới cho người dùng. */
    public function changePassword(User $user, string $password): void
    {
        $user->forceFill(['password' => $password])->save();
        $user->tokens()->delete();
    }
}
