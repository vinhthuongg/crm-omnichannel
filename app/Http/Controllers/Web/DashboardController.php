<?php

namespace App\Http\Controllers\Web;

use App\Actions\Web\GetDashboardViewDataAction;
use App\Actions\Web\GetDashboardChartDataAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, GetDashboardViewDataAction $action): View
    {
        return view('dashboard.index', $action->execute($request->user(), [
            'section' => $request->route('section', 'dashboard'),
            'search' => $request->string('q')->toString(),
            'period' => $request->string('period', 'week')->toString(),
            'activity_agent' => $request->string('activity_agent', 'all')->toString(),
            'activity_type' => $request->string('activity_type', 'all')->toString(),
            'activity_keyword' => $request->string('activity_keyword')->toString(),
        ]));
    }

    public function charts(Request $request, GetDashboardChartDataAction $action): JsonResponse
    {
        return response()->json($action->execute($request->user(), [
            'period' => $request->string('period', 'week')->toString(),
        ]));
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $request->user()->forceFill([
            'password' => Hash::make($validated['password']),
        ])->save();

        return back()->with('settings_status', 'Đã đổi mật khẩu thành công.');
    }
}
