<?php

namespace Modules\Dashboard\Actions;

use App\Models\User;
use Modules\Dashboard\Services\DashboardService;

class GetDashboardOverviewAction
{
    public function __construct(private readonly DashboardService $service)
    {
    }

    public function execute(User $user, array $filters = []): array
    {
        return $this->service->overview($user, $filters);
    }
}
