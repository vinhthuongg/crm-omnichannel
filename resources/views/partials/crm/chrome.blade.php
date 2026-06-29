@php($navLabels = [
    'dashboard' => 'Dashboard',
    'conversations' => 'Hội Thoại',
    'customers' => 'Khách Hàng',
    'agents' => 'Nhân Viên',
    'work_shifts' => 'Ca Trực',
    'channels' => 'Kết Nối Mạng Xã Hội',
    'activity' => 'Hoạt Động',
    'activity_log' => 'Thông Báo',
    'notifications' => 'Thông Báo',
    'settings' => 'Cài Đặt',
])

<aside class="crm-sidebar">
    <a class="crm-logo" href="{{ route('dashboard') }}">Toyota CRM</a>

    <nav class="side-nav" aria-label="CRM navigation">
        @foreach($navItems as $item)
            <a class="{{ ($activeSection ?? '') === $item['section'] ? 'active' : '' }}" href="{{ route($item['route']) }}">
                <span class="material-symbols-outlined nav-material-icon" aria-hidden="true">{{ $item['icon'] }}</span>{{ $navLabels[$item['section']] ?? $item['label'] }}
            </a>
        @endforeach
    </nav>

    <a class="team-switcher" href="{{ route('crm.agents') }}">
        <span>{{ substr($sidebar['team_name'], 0, 1) }}</span>
        <strong>{{ $sidebar['team_name'] }}</strong>
        <b>v</b>
    </a>
</aside>
