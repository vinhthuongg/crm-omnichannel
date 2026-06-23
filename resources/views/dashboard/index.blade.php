@extends('layouts.app', ['title' => $sectionTitle . ' - CRM'])

@section('content')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/morris.js/0.5.1/morris.css">

<div class="crm-shell" data-crm-shell>
    <aside class="crm-sidebar">
        <a class="crm-logo" href="{{ route('dashboard') }}">CRM</a>

        <nav class="side-nav" aria-label="CRM navigation">
            @foreach($navItems as $item)
                <a class="{{ $activeSection === $item['section'] ? 'active' : '' }}" href="{{ route($item['route']) }}">
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

        <section class="page-title">
            <div>
                <h1>{{ $sectionTitle }}</h1>
                <p>Omnichannel CRM workspace for Facebook and Zalo conversations.</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('crm.conversations') }}">Open Inbox</a>
                <a href="{{ route('crm.customers') }}">Customers</a>
            </div>
        </section>

        <section class="dashboard-grid">
            <div class="dashboard-main-column">
                <article class="panel summary-panel">
                    <div class="panel-head">
                        <div>
                            <p>Conversation Management Summary</p>
                            <h2>{{ number_format($weeklySummary['total']) }} Conversations <span class="{{ $weeklySummary['change'] >= 0 ? 'good' : 'bad' }}">{{ $weeklySummary['change'] >= 0 ? 'Up' : 'Down' }} {{ abs($weeklySummary['change']) }}%</span></h2>
                            <small>Last 7 days by status</small>
                        </div>
                        <div class="filter-buttons">
                            <a class="{{ $filters['period'] === 'week' ? 'active' : '' }}" href="{{ url()->current() }}?period=week">Week</a>
                            <a class="{{ $filters['period'] === 'month' ? 'active' : '' }}" href="{{ url()->current() }}?period=month">Month</a>
                            <a class="{{ $filters['period'] === 'year' ? 'active' : '' }}" href="{{ url()->current() }}?period=year">Year</a>
                        </div>
                    </div>

                    <div class="legend">
                        <span><b class="black"></b>Open</span>
                        <span><b class="dark"></b>Pending</span>
                        <span><b class="light"></b>Closed</span>
                    </div>

                    <div id="conversation-status-bar" class="morris-chart" aria-label="Conversation status chart"></div>
                </article>

                <div class="bottom-card-grid">
                    <article class="panel revenue-card">
                        <div class="panel-head compact-head">
                            <div>
                                <p>Conversation Trend</p>
                                <h2>{{ number_format($yearTrend['total']) }} <span class="{{ $yearTrend['change'] >= 0 ? 'good' : 'bad' }}">{{ $yearTrend['change'] >= 0 ? 'Up' : 'Down' }} {{ abs($yearTrend['change']) }}%</span></h2>
                            </div>
                            <a class="panel-button" href="{{ route('crm.reports', ['period' => 'year']) }}">Year</a>
                        </div>
                        <div id="conversation-trend-line" class="morris-card-chart" aria-label="Conversation trend chart"></div>
                    </article>

                    <article class="panel allocation-card">
                        <div class="panel-head compact-head">
                            <div>
                                <p>Platform Allocation</p>
                                <h2>{{ number_format($channelMetrics->sum('messages')) }} Messages</h2>
                            </div>
                            <a class="panel-button" href="{{ route('crm.channels') }}">Channels</a>
                        </div>
                        <div id="platform-allocation-bar" class="morris-card-chart" aria-label="Platform allocation chart"></div>
                    </article>
                </div>
            </div>

            <aside class="dashboard-side-column">
                <article class="panel completed-card">
                    <p>Inbox Status</p>
                    <div>
                        <h2>{{ number_format($statusCounts['open']) }} Open</h2>
                        <a href="{{ route('crm.conversations', ['status' => 'open']) }}">View</a>
                    </div>
                    <div>
                        <h2>{{ number_format($notificationCount) }} Needs Reply</h2>
                        <a href="{{ route('crm.notifications') }}">View</a>
                    </div>
                </article>

                <article class="panel crm-mini-panel">
                    <div class="panel-head compact-head">
                        <div>
                            <p>Channels</p>
                        </div>
                        <a class="panel-button" href="{{ route('crm.channels') }}">Open</a>
                    </div>
                    @foreach($channelMetrics as $channel)
                        @php($channelKey = strtolower($channel['name']))
                        <a class="mini-row" href="{{ route('crm.channels', ['channel' => strtolower($channel['name'])]) }}">
                            <span class="channel-name">
                                <span class="channel-logo-frame">
                                    <img class="channel-logo channel-logo-{{ $channelKey }}" src="{{ $channelKey === 'zalo' ? '/assets/img_zalo.png' : '/assets/img_fb.png' }}" alt="{{ $channel['name'] }} logo">
                                </span>
                                {{ $channel['name'] }}
                            </span>
                            <strong class="channel-unread {{ $channel['unread_messages'] > 0 ? 'has-unread' : '' }}">{{ number_format($channel['unread_messages']) }}</strong>
                        </a>
                    @endforeach
                </article>
            </aside>
        </section>
    </main>
</div>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.9.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/raphael/2.1.0/raphael-min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/morris.js/0.5.1/morris.min.js"></script>
<script>
    document.querySelector('[data-sidebar-toggle]')?.addEventListener('click', function () {
        document.querySelector('[data-crm-shell]')?.classList.toggle('sidebar-collapsed');
    });

    $(function () {
        var chartUrl = @json(route('dashboard.charts', ['period' => $filters['period']]));
        var chartColors = ['#000000', '#555555', '#bfbfbf', '#e2e2e2'];

        $.ajax({
            type: 'GET',
            dataType: 'json',
            url: chartUrl
        }).done(function (data) {
            Morris.Bar({
                element: 'conversation-status-bar',
                data: data.conversation_status,
                xkey: 'period',
                ykeys: ['open', 'pending', 'closed'],
                labels: ['Open', 'Pending', 'Closed'],
                barColors: ['#000000', '#555555', '#cfcfcf'],
                gridTextColor: '#6f6f6f',
                gridLineColor: '#dddddd',
                stacked: true,
                resize: true,
                hideHover: 'auto'
            });

            Morris.Line({
                element: 'conversation-trend-line',
                data: data.trend,
                xkey: 'year',
                ykeys: ['value'],
                labels: ['Conversations'],
                parseTime: false,
                lineColors: ['#000000'],
                pointFillColors: ['#000000'],
                pointStrokeColors: ['#000000'],
                gridTextColor: '#6f6f6f',
                gridLineColor: '#dddddd',
                resize: true,
                hideHover: 'auto'
            });

            Morris.Bar({
                element: 'platform-allocation-bar',
                data: data.channels,
                xkey: 'label',
                ykeys: ['value'],
                labels: ['Messages'],
                barColors: ['#000000'],
                gridTextColor: '#6f6f6f',
                gridLineColor: '#dddddd',
                resize: true,
                hideHover: 'auto'
            });

        });
    });
</script>
@endsection
