@extends('layouts.app', ['title' => $sectionTitle . ' - CRM'])

@section('content')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/morris.js/0.5.1/morris.css">

<div class="crm-shell" data-crm-shell>
    @include('partials.crm.chrome')

    <main class="crm-main">
        @include('partials.crm.topbar')

        @if(($activeSection ?? 'dashboard') === 'agents')
            <section class="agent-report-page">
                <form class="agent-filter-bar" method="GET" action="{{ route('crm.agents') }}">
                    <label>
                        <span>Thoi gian</span>
                        <select name="period">
                            @foreach($agentDashboard['filters']['periods'] as $periodOption)
                                <option value="{{ $periodOption['value'] }}">{{ $periodOption['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>
                        <span>Chi nhanh</span>
                        <select name="branch">
                            @foreach($agentDashboard['filters']['branches'] as $branchOption)
                                <option>{{ $branchOption }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>
                        <span>Nhom nhan vien</span>
                        <select name="group">
                            @foreach($agentDashboard['filters']['groups'] as $groupOption)
                                <option>{{ $groupOption }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button type="submit">
                        <span class="material-symbols-outlined" aria-hidden="true">filter_alt</span>
                        Loc them
                    </button>
                </form>

                <section class="agent-kpi-grid">
                    @foreach($agentDashboard['cards'] as $card)
                        <article class="agent-kpi-card">
                            <div>
                                <p>{{ $card['label'] }}</p>
                                <h2>{{ $card['value'] }}@if($card['suffix'] !== '') <small>{{ $card['suffix'] }}</small>@endif</h2>
                                <span class="{{ $card['tone'] }}">{{ $card['change'] }}</span>
                            </div>
                            <b>
                                <span class="material-symbols-outlined" aria-hidden="true">{{ $card['icon'] }}</span>
                            </b>
                        </article>
                    @endforeach
                </section>

                <section class="agent-chart-grid">
                    <article class="agent-panel">
                        <header>
                            <h3>Hoi thoai xu ly theo nhan vien</h3>
                            <button type="button" aria-label="Tuy chon">
                                <span class="material-symbols-outlined" aria-hidden="true">more_vert</span>
                            </button>
                        </header>
                        <div id="agent-handled-bar" class="agent-chart" aria-label="Hoi thoai xu ly theo nhan vien"></div>
                    </article>

                    <article class="agent-panel">
                        <header>
                            <h3>Toc do phan hoi trung binh (phut)</h3>
                            <span class="agent-chart-legend"><i></i>Toan doi</span>
                        </header>
                        <div id="agent-response-line" class="agent-chart" aria-label="Toc do phan hoi trung binh"></div>
                    </article>
                </section>

                <section class="agent-table-panel">
                    <header>
                        <h3>Chi tiet hieu suat nhan vien</h3>
                        <label>
                            <span class="material-symbols-outlined" aria-hidden="true">search</span>
                            <input type="search" placeholder="Tim nhan vien...">
                        </label>
                    </header>
                    <div class="agent-table-wrap">
                        <table class="agent-performance-table">
                            <thead>
                                <tr>
                                    <th>Nhan vien</th>
                                    <th>Tong hoi thoai</th>
                                    <th>Da xu ly</th>
                                    <th>Dang xu ly</th>
                                    <th>TG phan hoi TB</th>
                                    <th>SDT thu thap</th>
                                    <th>Danh gia</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($agentDashboard['rows'] as $agent)
                                    <tr>
                                        <td>
                                            <span class="agent-avatar">
                                                @if($agent['avatar'])
                                                    <img src="{{ $agent['avatar'] }}" alt="{{ $agent['name'] }}">
                                                @else
                                                    {{ $agent['initial'] }}
                                                @endif
                                            </span>
                                            {{ $agent['name'] }}
                                        </td>
                                        <td>{{ number_format($agent['total_conversations']) }}</td>
                                        <td><a href="{{ route('crm.conversations', ['status' => 'mine']) }}">{{ number_format($agent['processed_conversations']) }}</a></td>
                                        <td>{{ number_format($agent['active_conversations']) }}</td>
                                        <td>{{ number_format($agent['avg_response_minutes'], 1) }} p</td>
                                        <td>{{ number_format($agent['phone_collected']) }}</td>
                                        <td><span class="agent-rating">{{ $agent['rating'] }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <footer>
                        <span>Hien thi 1-{{ min(4, count($agentDashboard['rows'])) }} tren tong {{ count($agentDashboard['rows']) }}</span>
                        <nav aria-label="Pagination">
                            <b>1</b><a href="#">2</a><a href="#">3</a><span>...</span><a href="#">›</a>
                        </nav>
                    </footer>
                </section>
            </section>
        @else
        <section class="page-title">
            <div>
                <h1>{{ $sectionTitle }}</h1>
                <p>Omnichannel CRM workspace for Facebook and Zalo conversations.</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('crm.conversations') }}">
                    Open Inbox
                </a>
                <a href="{{ route('crm.customers') }}">
                    Customers
                </a>
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
                            <a class="panel-button" href="{{ route('crm.reports', ['period' => 'year']) }}">
                                Year
                            </a>
                        </div>
                        <div id="conversation-trend-line" class="morris-card-chart" aria-label="Conversation trend chart"></div>
                    </article>

                    <article class="panel allocation-card">
                        <div class="panel-head compact-head">
                            <div>
                                <p>Platform Allocation</p>
                                <h2>{{ number_format($channelMetrics->sum('messages')) }} Messages</h2>
                            </div>
                            <a class="panel-button" href="{{ route('crm.channels') }}">
                                Channels
                            </a>
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
                        <a href="{{ route('crm.conversations', ['status' => 'open']) }}">
                            View
                        </a>
                    </div>
                    <div>
                        <h2>{{ number_format($notificationCount) }} Needs Reply</h2>
                        <a href="{{ route('crm.notifications') }}">
                            View
                        </a>
                    </div>
                </article>

                <article class="panel crm-mini-panel">
                    <div class="panel-head compact-head">
                        <div>
                            <p>Channels</p>
                        </div>
                        <a class="panel-button" href="{{ route('crm.channels') }}">
                            Open
                        </a>
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
        @endif
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
        var activeSection = @json($activeSection ?? 'dashboard');
        var agentBarData = @json($agentDashboard['bar'] ?? []);
        var agentLineData = @json($agentDashboard['responseLine'] ?? []);

        if (activeSection === 'agents') {
            if (!agentBarData.length) {
                agentBarData = [{agent: 'Chua co', value: 0}];
            }

            if (!agentLineData.length) {
                agentLineData = [{hour: '08:00', value: 0}];
            }

            Morris.Bar({
                element: 'agent-handled-bar',
                data: agentBarData,
                xkey: 'agent',
                ykeys: ['value'],
                labels: ['Hoi thoai'],
                barColors: ['#d70616'],
                gridTextColor: '#6f6f6f',
                gridLineColor: '#ececec',
                resize: true,
                hideHover: 'auto'
            });

            Morris.Line({
                element: 'agent-response-line',
                data: agentLineData,
                xkey: 'hour',
                ykeys: ['value'],
                labels: ['Phut'],
                parseTime: false,
                lineColors: ['#0f62fe'],
                pointFillColors: ['#ffffff'],
                pointStrokeColors: ['#0f62fe'],
                gridTextColor: '#6f6f6f',
                gridLineColor: '#ececec',
                resize: true,
                hideHover: 'auto'
            });

            return;
        }

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
