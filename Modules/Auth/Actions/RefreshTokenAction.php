<?php

namespace Modules\Auth\Actions;

use App\Models\User;
use Modules\Auth\Services\AuthService;

class RefreshTokenAction
{
    public function __construct(private readonly AuthService $service)
    {
    }

    public function execute(User $user, string $deviceName): array
    {
        return $this->service->refresh($user, $deviceName);
    }
}