<?php

namespace Modules\Text\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
        $expectedState = (string) $request->session()->pull('text_oauth_state', '');

        if ($state !== '' && $expectedState !== '' && $state !== $expectedState) {
            Log::warning('Text.com OAuth callback rejected by invalid state', [
                'has_state' => true,
                'has_expected_state' => true,
                'user_id' => $request->user()?->id,
            ]);

            return redirect()
                ->route('crm.channels')
                ->with('error', 'Text.com OAuth state khong hop le. Hay thu ket noi lai.');
        }

        if ($state === '') {
            Log::warning('Text.com OAuth callback did not include state, continuing with authenticated admin session', [
                'has_expected_state' => $expectedState !== '',
                'user_id' => $request->user()?->id,
            ]);
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            Log::warning('Text.com OAuth callback missing code', [
                'user_id' => $request->user()?->id,
                'query' => $request->query(),
            ]);

            return redirect()
                ->route('crm.channels')
                ->with('error', 'Text.com khong tra ve authorization code.');
        }

        try {
            $payload = $oauth->exchangeCode($code);
            $oauth->persistAgentToken($payload);
        } catch (\Throwable $exception) {
            Log::warning('Text.com OAuth token exchange failed', [
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return redirect()
                ->route('crm.channels')
                ->with('error', 'Ket noi Text.com that bai: '.$exception->getMessage());
        }

        Log::info('Text.com OAuth token stored', [
            'user_id' => $request->user()?->id,
            'organization_id' => $payload['organization_id'] ?? null,
            'scope' => $payload['scope'] ?? null,
            'expires_in' => $payload['expires_in'] ?? null,
        ]);

        return redirect()
            ->route('crm.channels')
            ->with('status', 'Da ket noi Text.com OAuth thanh cong. Hay chay php artisan optimize:clear tren server neu dang cache config.');
    }
}
