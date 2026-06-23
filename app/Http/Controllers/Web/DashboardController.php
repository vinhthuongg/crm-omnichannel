<?php

namespace App\Http\Controllers\Web;

use App\Actions\Web\GetDashboardViewDataAction;
use App\Actions\Web\GetDashboardChartDataAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, GetDashboardViewDataAction $action): View
    {
        return view('dashboard.index', $action->execute($request->user(), [
            'section' => $request->route('section', 'dashboard'),
            'search' => $request->string('q')->toString(),
            'period' => $request->string('period', 'week')->toString(),
        ]));
    }

    public function charts(Request $request, GetDashboardChartDataAction $action): JsonResponse
    {
        return response()->json($action->execute($request->user(), [
            'period' => $request->string('period', 'week')->toString(),
        ]));
    }
}
