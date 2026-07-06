@php($navLabels = [
    'dashboard' => 'Dashboard',
    'conversations' => 'Hội thoại',
    'customers' => 'Khách hàng',
    'agents' => 'Nhân viên',
    'work_shifts' => 'Ca trực',
    'channels' => 'Kết nối kênh',
    'activity' => 'Hoạt động',
    'activity_log' => 'Thông báo',
    'notifications' => 'Thông báo',
    'settings' => 'Cài đặt',
])

<aside class="crm-sidebar">
    <a class="crm-logo" href="{{ route('dashboard') }}" aria-label="Toyota CRM">
        <img src="{{ asset('assets/logo.png') }}" alt="Toyota CRM">
    </a>

    <nav class="side-nav" aria-label="CRM navigation">
        @foreach($navItems as $item)
            <a class="{{ ($activeSection ?? '') === $item['section'] ? 'active' : '' }}" href="{{ route($item['route']) }}">
                <span class="material-symbols-outlined nav-material-icon" aria-hidden="true">{{ $item['icon'] }}</span>
                <span>{{ $navLabels[$item['section']] ?? $item['label'] }}</span>
            </a>
        @endforeach
    </nav>

    <details class="team-switcher">
        <summary>
            <span class="team-switcher-avatar">{{ mb_strtoupper(mb_substr($currentUser->name, 0, 1)) }}</span>
            <span class="team-switcher-name">{{ $currentUser->name }}</span>
            <span class="material-symbols-outlined" aria-hidden="true">expand_more</span>
        </summary>

        <div class="team-switcher-menu">
            @can('user.manage')
                <a href="{{ route('crm.admin.database.launch') }}" target="_blank" rel="noopener">
                    <span class="material-symbols-outlined" aria-hidden="true">database</span>
                    <span>phpMyAdmin</span>
                </a>
            @endcan
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit">
                    <span class="material-symbols-outlined" aria-hidden="true">logout</span>
                    <span>Đăng xuất</span>
                </button>
            </form>
        </div>
    </details>
</aside>
