<?php

namespace App\Http\Controllers\Web;

use App\Actions\Web\LoginUserAction;
use App\Actions\Web\LogoutUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request, LoginUserAction $action): RedirectResponse
    {
        $action->execute($request->validated());

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request, LogoutUserAction $action): RedirectResponse
    {
        $action->execute($request);

        return redirect()->route('login');
    }
}
