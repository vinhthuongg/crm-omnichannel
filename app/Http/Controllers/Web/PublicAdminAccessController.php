<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class PublicAdminAccessController extends Controller
{
    public function __invoke(string $token): RedirectResponse
    {
        abort_unless((bool) config('services.public_admin.enabled'), 404);

        $expected = trim((string) config('services.public_admin.token', ''));
        abort_if($expected === '' || ! hash_equals($expected, $token), 404);

        $email = trim((string) config('services.public_admin.email', 'admin@oldthread.store'));
        $user = User::query()
            ->where('email', $email)
            ->where('is_active', true)
            ->firstOrFail();

        abort_unless($user->hasRole('Admin') || $user->can('user.manage'), 403);

        Auth::login($user);
        request()->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
