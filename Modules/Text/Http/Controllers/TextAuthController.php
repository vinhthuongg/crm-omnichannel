<?php

namespace Modules\Text\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Text\Services\TextOAuthService;

class TextAuthController extends Controller
{
    public function redirect(Request $request, TextOAuthService $oauth): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put('text_oauth_state', $state);

        return redirect()->away($oauth->authorizationUrl($state));
    }

    public function callback(Request $request, TextOAuthService $oauth): RedirectResponse
    {
        abort_unless($request->user()?->can('user.manage'), 403);

        $state = (string) $request->query('state', '');

        if ($state === '' || $state !== $request->session()->pull('text_oauth_state')) {
            return redirect()
                ->route('crm.channels')
                ->with('error', 'Text.com OAuth state khong hop le. Hay thu ket noi lai.');
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            return redirect()
                ->route('crm.channels')
                ->with('error', 'Text.com khong tra ve authorization code.');
        }

        try {
            $payload = $oauth->exchangeCode($code);
            $oauth->persistAgentToken($payload);
        } catch (\Throwable $exception) {
            return redirect()
                ->route('crm.channels')
                ->with('error', 'Ket noi Text.com that bai: '.$exception->getMessage());
        }

        return redirect()
            ->route('crm.channels')
            ->with('status', 'Da ket noi Text.com OAuth thanh cong. Hay chay php artisan optimize:clear tren server neu dang cache config.');
    }
}
