@extends('layouts.app', ['title' => 'Customers - CRM'])

@section('content')
<div class="crm-shell customers-page" data-crm-shell>
    <aside class="crm-sidebar">
        <a class="crm-logo" href="{{ route('dashboard') }}">CRM</a>

        <nav class="side-nav" aria-label="CRM navigation">
            @foreach($navItems as $item)
                <a class="{{ $item['section'] === 'customers' ? 'active' : '' }}" href="{{ route($item['route']) }}">
                    <span>{{ $item['icon'] }}</span>{{ $item['label'] }}
                </a>
            @endforeach
        </nav>

        <a class="team-switcher" href="{{ route('crm.agents') }}">
            <span>{{ substr($sidebar['team_name'], 0, 1) }}</span>
            <strong>{{ $sidebar['team_name'] }}</strong>
            <b>v</b>
        </a>
    </aside>

    <main class="crm-main">
        <header class="crm-topbar">
            <button class="collapse-button" type="button" aria-label="Toggle sidebar" data-sidebar-toggle>&lt;&gt;</button>
            <div class="topbar-spacer"></div>
            <a class="help-link" href="{{ route('crm.settings', ['panel' => 'help']) }}"><span>?</span> Help Center</a>
            <a class="profile-link" href="{{ route('crm.settings', ['panel' => 'profile']) }}">{{ $currentUser->name }}</a>
            <form method="POST" action="{{ route('logout') }}" class="account-menu">
                @csrf
                <button type="submit">Logout</button>
            </form>
        </header>

        <section class="page-title customers-title">
            <div>
                <h1>Customers</h1>
                <p>Danh sach khach hang da tung nhan tin qua Facebook Messenger hoac Zalo OA.</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('crm.conversations') }}">Open Inbox</a>
            </div>
        </section>

        <section class="customer-summary-grid" aria-label="Customer summary">
            <article class="panel customer-summary-card">
                <p>Tong khach da nhan tin</p>
                <strong>{{ number_format($summary['total']) }}</strong>
            </article>
            <article class="panel customer-summary-card">
                <p>Facebook</p>
                <strong>{{ number_format($summary['facebook']) }}</strong>
            </article>
            <article class="panel customer-summary-card">
                <p>Zalo</p>
                <strong>{{ number_format($summary['zalo']) }}</strong>
            </article>
        </section>

        <section class="panel customers-panel">
            <form class="customers-toolbar" method="GET" action="{{ route('crm.customers') }}">
                <label>
                    <span>Tim khach hang</span>
                    <input name="q" value="{{ $filters['q'] }}" placeholder="Ten, so dien thoai, email hoac ID kenh">
                </label>
                <label>
                    <span>Kenh</span>
                    <select name="channel">
                        <option value="">Tat ca kenh</option>
                        <option value="facebook" @selected($filters['channel'] === 'facebook')>Facebook</option>
                        <option value="zalo" @selected($filters['channel'] === 'zalo')>Zalo</option>
                    </select>
                </label>
                <button type="submit">Loc</button>
                @if($filters['q'] !== '' || $filters['channel'] !== '')
                    <a href="{{ route('crm.customers') }}">Xoa loc</a>
                @endif
            </form>

            <div class="customers-table-wrap">
                <table class="customers-table">
                    <thead>
                        <tr>
                            <th>Khach hang</th>
                            <th>Kenh</th>
                            <th>Lien he</th>
                            <th>Hoi thoai</th>
                            <th>Phu trach</th>
                            <th>Lan nhan gan nhat</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($customers as $customer)
                            @php($conversation = $latestConversations->get($customer->id))
                            <tr>
                                <td>
                                    <div class="customer-cell">
                                        <span class="customer-avatar">
                                            @if($customer->avatar)
                                                <img src="{{ $customer->avatar }}" alt="{{ $customer->name }}">
                                            @else
                                                {{ strtoupper(substr($customer->name ?: 'K', 0, 1)) }}
                                            @endif
                                        </span>
                                        <div>
                                            <strong>{{ $customer->name ?: 'Khach hang #'.$customer->id }}</strong>
                                            <small>ID {{ $customer->id }}</small>
                                            @if($customer->tags->isNotEmpty())
                                                <div class="customer-tags">
                                                    @foreach($customer->tags->take(3) as $tag)
                                                        <span style="--tag-color: {{ $tag->color ?: '#111827' }}">{{ $tag->name }}</span>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="customer-channels">
                                        @foreach($customer->channels as $channel)
                                            <span class="channel-pill channel-pill-{{ $channel->channel }}">{{ ucfirst($channel->channel) }}</span>
                                        @endforeach
                                    </div>
                                </td>
                                <td>
                                    <div class="customer-contact-lines">
                                        <span>{{ $customer->phone ?: 'Chua co so dien thoai' }}</span>
                                        <small>{{ $customer->email ?: 'Chua co email' }}</small>
                                    </div>
                                </td>
                                <td>
                                    <strong>{{ number_format($customer->conversations_count) }}</strong>
                                    <small>{{ number_format((int) $customer->messages_count) }} tin nhan</small>
                                </td>
                                <td>
                                    {{ $conversation?->assignee?->name ?: 'Chua gan nhan vien' }}
                                </td>
                                <td>
                                    {{ $customer->last_message_at ? \Illuminate\Support\Carbon::parse($customer->last_message_at)->format('d/m/Y H:i') : 'Chua co' }}
                                </td>
                                <td>
                                    @if($conversation)
                                        <a class="customer-open-link" href="{{ route('crm.conversations.show', $conversation) }}">Mo chat</a>
                                    @else
                                        <span class="customer-muted">Khong co quyen</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <div class="customers-empty">
                                        <h2>Chua co khach hang phu hop</h2>
                                        <p>Khach hang se xuat hien o day sau khi nhan tin vao Facebook Messenger hoac Zalo OA.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="customers-pagination">
                {{ $customers->links() }}
            </div>
        </section>
    </main>
</div>

<script>
    document.querySelector('[data-sidebar-toggle]')?.addEventListener('click', function () {
        document.querySelector('[data-crm-shell]')?.classList.toggle('sidebar-collapsed');
    });
</script>
@endsection
