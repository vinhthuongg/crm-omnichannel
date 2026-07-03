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
    <a class="crm-logo" href="{{ route('dashboard') }}">Toyota CRM</a>

    <nav class="side-nav" aria-label="CRM navigation">
        @foreach($navItems as $item)
            <a class="{{ ($activeSection ?? '') === $item['section'] ? 'active' : '' }}" href="{{ route($item['route']) }}">
                <span class="material-symbols-outlined nav-material-icon" aria-hidden="true">{{ $item['icon'] }}</span>
                <span>{{ $navLabels[$item['section']] ?? $item['label'] }}</span>
            </a>
        @endforeach
    </nav>

    <a class="team-switcher" href="{{ route('crm.agents') }}">
        <span>{{ substr($sidebar['team_name'], 0, 1) }}</span>
        <span>{{ $sidebar['team_name'] }}</span>
        <span class="material-symbols-outlined" aria-hidden="true">expand_more</span>
    </a>
</aside>
