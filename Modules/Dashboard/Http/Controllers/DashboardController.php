<?php

namespace Modules\Dashboard\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Dashboard\Actions\GetDashboardOverviewAction;
use Modules\Shared\Http\Controllers\ApiController;

class DashboardController extends ApiController
{
    public function overview(Request $request, GetDashboardOverviewAction $action)
    {
        abort_unless($request->user()->can('report.view'), 403);

        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json(['data' => $action->execute($request->user(), $filters)]);
    }
}
