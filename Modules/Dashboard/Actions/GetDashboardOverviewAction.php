<?php

namespace Modules\Dashboard\Actions;

use Modules\Dashboard\Services\DashboardService;

class GetDashboardOverviewAction
{
    public function __construct(private readonly DashboardService $service)
    {
    }

    public function execute(): array
    {
        return $this->service->overview();
    }
}