<?php

namespace Modules\Auth\Actions;

use App\Models\User;
use Modules\Auth\Services\AuthService;

class RefreshTokenAction
{
    /** Nhận AuthService để thay thế access token hiện tại. */
    public function __construct(private readonly AuthService $service)
    {
    }

    /** Thu hồi token cũ và phát hành token mới cho thiết bị yêu cầu. */
    public function execute(User $user, string $deviceName): array
    {
        return $this->service->refresh($user, $deviceName);
    }
}
