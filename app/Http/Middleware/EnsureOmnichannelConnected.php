<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Facebook\Models\FacebookPage;
use Symfony\Component\HttpFoundation\Response;

class EnsureOmnichannelConnected
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->hasConnectedFacebook() || $this->hasConnectedZalo()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'He thong chua duoc Admin ket noi Facebook Messenger hoac Zalo OA.',
            ], 409);
        }

        if ($request->user()?->can('user.manage')) {
            return redirect()->route('facebook.pages')->withErrors([
                'facebook' => 'Vui long ket noi Facebook Messenger hoac Zalo OA truoc khi mo Conversations.',
            ]);
        }

        return redirect()->route('crm.settings')->withErrors([
            'channels' => 'He thong chua duoc Admin ket noi Facebook Messenger hoac Zalo OA.',
        ]);
    }

    private function hasConnectedFacebook(): bool
    {
        return FacebookPage::query()
            ->where('token_status', 'valid')
            ->exists();
    }

    private function hasConnectedZalo(): bool
    {
        return trim((string) config('services.zalo.access_token')) !== '';
    }
}
