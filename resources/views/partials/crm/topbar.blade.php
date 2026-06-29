<header class="crm-topbar">
    <button class="collapse-button" type="button" aria-label="Toggle sidebar" data-sidebar-toggle>&lt;&gt;</button>
    <div class="topbar-brand">
        <strong>Toyota CRM</strong>
        <span>Omnichannel CRM</span>
    </div>
    <form class="topbar-global-search" method="GET" action="{{ route('crm.conversations') }}">
        <input
            type="search"
            name="q"
            placeholder="Tim kiem khach hang, tin nhan..."
            value="{{ data_get($filters ?? [], 'search', data_get($filters ?? [], 'q', '')) }}"
            data-auto-search-input
        >
    </form>
    <div class="topbar-spacer"></div>
    <a class="help-link" href="{{ route('crm.settings', ['panel' => 'help']) }}"><span>?</span> Help Center</a>
    <a class="profile-link" href="{{ route('crm.settings', ['panel' => 'profile']) }}">{{ $currentUser->name }}</a>
    <form method="POST" action="{{ route('logout') }}" class="account-menu">
        @csrf
        <button type="submit">Dang xuat</button>
    </form>
</header>
