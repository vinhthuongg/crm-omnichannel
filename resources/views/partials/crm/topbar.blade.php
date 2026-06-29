<header class="crm-topbar">
    <button class="collapse-button" type="button" aria-label="Toggle sidebar" data-sidebar-toggle>
        <span class="material-symbols-outlined" aria-hidden="true">menu</span>
    </button>
    <div class="topbar-brand">
        <strong>Toyota CRM</strong>
        <span>Omnichannel CRM</span>
    </div>
    <form class="topbar-global-search" method="GET" action="{{ route('crm.conversations') }}">
        <span class="material-symbols-outlined" aria-hidden="true">search</span>
        <input
            type="search"
            name="q"
            placeholder="Tim kiem khach hang, tin nhan..."
            value="{{ data_get($filters ?? [], 'search', data_get($filters ?? [], 'q', '')) }}"
            data-auto-search-input
        >
    </form>
    <div class="topbar-spacer"></div>
    <a class="help-link" href="{{ route('crm.settings', ['panel' => 'help']) }}">
        Help Center
    </a>
</header>
