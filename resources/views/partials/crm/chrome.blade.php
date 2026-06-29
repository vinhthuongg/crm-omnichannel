@php($navLabels = [
    'dashboard' => 'Dashboard',
    'conversations' => 'Hoi thoai',
    'customers' => 'Khach hang',
    'agents' => 'Nhan vien',
    'work_shifts' => 'Ca truc',
    'channels' => 'Ket noi mang xa hoi',
    'reports' => 'Bao cao',
    'activity' => 'Hoat dong',
    'activity_log' => 'Thong bao',
    'notifications' => 'Thong bao',
    'settings' => 'Cai dat',
])

<aside class="crm-sidebar">
    <a class="crm-logo" href="{{ route('dashboard') }}">Toyota CRM</a>

    <a class="sidebar-user-card" href="{{ route('crm.settings', ['panel' => 'profile']) }}">
        <span class="sidebar-user-avatar">
            @if($currentUser->avatar ?? null)
                <img src="{{ $currentUser->avatar }}" alt="{{ $currentUser->name }}">
            @else
                {{ strtoupper(substr($currentUser->name, 0, 1)) }}
            @endif
        </span>
        <span>
            <strong>{{ $currentUser->name }}</strong>
            <small>{{ $currentUser->can('conversation.view_all') ? 'CRM Admin' : 'Sales Consultant' }}</small>
        </span>
    </a>

    <nav class="side-nav" aria-label="CRM navigation">
        @foreach($navItems as $item)
            <a class="{{ ($activeSection ?? '') === $item['section'] ? 'active' : '' }}" href="{{ route($item['route']) }}">
                <span>{{ $item['icon'] }}</span>{{ $navLabels[$item['section']] ?? $item['label'] }}
            </a>
        @endforeach
    </nav>

    <a class="team-switcher" href="{{ route('crm.agents') }}">
        <span>{{ substr($sidebar['team_name'], 0, 1) }}</span>
        <strong>{{ $sidebar['team_name'] }}</strong>
        <b>v</b>
    </a>
</aside>
