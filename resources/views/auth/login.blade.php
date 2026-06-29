@extends('layouts.app', ['title' => 'Đăng nhập CRM', 'bodyClass' => 'auth-page'])

@section('content')
<main class="login-shell">
    <section class="login-panel" aria-labelledby="login-title">
        <div class="brand-mark">CRM</div>
        <div class="login-heading">
            <p>Omnichannel Console</p>
            <h1 id="login-title">Đăng nhập</h1>
        </div>

        <form method="POST" action="{{ route('login.store') }}" class="login-form">
            @csrf

            <label>
                <span>Email</span>
                <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" autofocus required>
            </label>
            @error('email')
                <p class="field-error">{{ $message }}</p>
            @enderror

            <label>
                <span>Mật khẩu</span>
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            @error('password')
                <p class="field-error">{{ $message }}</p>
            @enderror

            <label class="check-row">
                <input type="checkbox" name="remember" value="1">
                <span>Giữ đăng nhập</span>
            </label>

            <button type="submit">
                <span class="material-symbols-outlined" aria-hidden="true">login</span>
                Vào dashboard
            </button>
        </form>

        <div class="login-divider"><span>hoac</span></div>
        <a class="facebook-login-button" href="{{ route('facebook.redirect') }}">
            <span class="material-symbols-outlined" aria-hidden="true">account_circle</span>
            Dang nhap bang Facebook
        </a>
    </section>

    <aside class="login-insight">
        <div>
            <p class="eyebrow">Facebook · Zalo · Realtime</p>
            <h2>Quản lý hội thoại, nhân viên và phản hồi khách hàng trong một màn hình.</h2>
        </div>
        <dl class="insight-grid">
            <div>
                <dt>API-first</dt>
                <dd>Sanctum, Reverb, Queue</dd>
            </div>
            <div>
                <dt>Roles</dt>
                <dd>Admin, CSKH</dd>
            </div>
        </dl>
    </aside>
</main>
@endsection
