@extends('layouts.app', ['title' => $sectionTitle . ' - CRM'])

@section('content')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/morris.js/0.5.1/morris.css">

<div class="crm-shell" data-crm-shell>
    @include('partials.crm.chrome')

    <main class="crm-main">
        @include('partials.crm.topbar')

        @if(($activeSection ?? 'dashboard') === 'channels')
            <section class="connection-page">
                <header class="connection-header">
                    <h1>{{ $channelManagement['title'] }}</h1>
                    <p>{{ $channelManagement['subtitle'] }}</p>
                </header>

                <section class="connection-grid">
                    @foreach($channelManagement['cards'] as $channel)
                        <article class="connection-card connection-card-{{ $channel['key'] }}">
                            <header>
                                <span class="connection-icon">
                                    @if($channel['icon_label'])
                                        {{ $channel['icon_label'] }}
                                    @else
                                        <span class="material-symbols-outlined" aria-hidden="true">{{ $channel['icon'] }}</span>
                                    @endif
                                </span>
                                <div>
                                    <h2>{{ $channel['title'] }}</h2>
                                    <p class="{{ $channel['status_tone'] }}">
                                        <i></i>{{ $channel['status'] }}
                                    </p>
                                </div>
                                @if($channel['menu'])
                                    <button type="button" aria-label="Tuy chon">
                                        <span class="material-symbols-outlined" aria-hidden="true">more_vert</span>
                                    </button>
                                @endif
                            </header>

                            <dl>
                                <div>
                                    <dt>Tài khoản</dt>
                                    <dd>{{ $channel['account'] }}</dd>
                                </div>
                                <div>
                                    <dt>Đồng bộ lần cuối</dt>
                                    <dd>{{ $channel['last_sync'] }}</dd>
                                </div>
                                <div>
                                    <dt>Webhook</dt>
                                    <dd>
                                        <span class="connection-badge {{ $channel['webhook_tone'] }}">{{ $channel['webhook'] }}</span>
                                    </dd>
                                </div>
                            </dl>

                            <footer>
                                @if($channel['connected'])
                                    <button type="button" class="connection-outline">Ngắt kết nối</button>
                                    @if($channel['sync_url'])
                                        <form method="POST" action="{{ $channel['sync_url'] }}">
                                            @csrf
                                            <button type="submit" class="connection-primary">
                                                <span class="material-symbols-outlined" aria-hidden="true">sync</span>
                                                Đồng bộ
                                            </button>
                                        </form>
                                    @else
                                        <button type="button" class="connection-primary">
                                            <span class="material-symbols-outlined" aria-hidden="true">sync</span>
                                            Đồng bộ
                                        </button>
                                    @endif
                                @else
                                    <a class="connection-primary is-full" href="{{ $channel['connect_url'] }}">
                                        <span class="material-symbols-outlined" aria-hidden="true">login</span>
                                        Kết nối ngay
                                    </a>
                                @endif
                            </footer>
                        </article>
                    @endforeach
                </section>
            </section>
        @elseif(($activeSection ?? 'dashboard') === 'agents')
            <section class="agent-report-page">
                <header class="agent-page-header">
                    <div>
                        <h1>Hieu suat nhan vien</h1>
                        <p>Du lieu tong hop tu hoi thoai that dang co trong he thong.</p>
                    </div>
                </header>

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
                        <span>Hien thi {{ count($agentDashboard['rows']) }} nhan vien</span>
                    </footer>
                </section>
            </section>
        @elseif(($activeSection ?? 'dashboard') === 'activity')
            @php
                $activityTotal = $activityLogs->count();
                $activityToday = $activityLogs->filter(fn ($log) => $log->created_at?->isToday())->count();
                $activityUsers = $activityLogs->pluck('user_id')->filter()->unique()->count();
            @endphp

            <section class="activity-log-page">
                <header class="activity-page-header">
                    <div>
                        <h1>Activity Log</h1>
                        <span>Track all system actions, assignments, and customer interactions.</span>
                    </div>
                </header>

                <section class="activity-filter-panel" aria-label="Activity filters">
                    <article>
                        <span>Total</span>
                        <strong>{{ number_format($activityTotal) }}</strong>
                    </article>
                    <article>
                        <span>Today</span>
                        <strong>{{ number_format($activityToday) }}</strong>
                    </article>
                    <article>
                        <span>Agents</span>
                        <strong>{{ number_format($activityUsers) }}</strong>
                    </article>
                </section>

                <section class="activity-feed-panel">
                    <header>
                        <h2>Recent Activity</h2>
                    </header>

                    <div class="activity-feed">
                        @forelse($activityLogs as $log)
                            @php
                                $metadata = collect($log->metadata ?? []);
                                $action = (string) $log->action;
                                $actionLabel = \Illuminate\Support\Str::headline(str_replace(['.', '_', '-'], ' ', $action));
                                $actor = $log->user?->name ?: 'System';
                                $subjectLabel = $log->subject_type ? class_basename($log->subject_type).' #'.$log->subject_id : 'CRM';
                                $isSystem = ! $log->user_id || str_contains($action, 'system') || str_contains($action, 'auto');
                                $channel = strtolower((string) ($metadata->get('channel') ?: $metadata->get('source') ?: 'System'));
                                $badge = in_array($channel, ['facebook', 'zalo'], true) ? ucfirst($channel) : 'System';
                                $detail = $metadata->isNotEmpty()
                                    ? $metadata->map(fn ($value, $key) => \Illuminate\Support\Str::headline((string) $key).': '.(is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE)))->take(1)->implode('')
                                    : '# '.$subjectLabel;
                            @endphp
                            <article class="activity-feed-item {{ $isSystem ? 'is-system' : '' }}">
                                <span class="activity-feed-icon">
                                    @if($isSystem)
                                        <span class="material-symbols-outlined" aria-hidden="true">smart_toy</span>
                                    @else
                                        {{ strtoupper(substr($actor, 0, 1)) }}
                                    @endif
                                </span>
                                <div class="activity-card">
                                    <div class="activity-card-main">
                                        <p>
                                            <strong>{{ $actor }}</strong>
                                            <span>{{ strtolower($actionLabel ?: 'updated activity') }}</span>
                                            <a href="{{ route('crm.activity') }}">{{ $subjectLabel }}</a>
                                        </p>
                                        <div class="activity-meta-line">
                                            <span>{{ $detail }}</span>
                                            <b class="activity-badge {{ strtolower($badge) }}">{{ $badge }}</b>
                                        </div>
                                    </div>
                                    <time>
                                        <strong>{{ $log->created_at?->isToday() ? $log->created_at?->format('H:i A') : $log->created_at?->diffForHumans() }}</strong>
                                        <span>{{ $log->created_at?->isToday() ? 'TODAY' : $log->created_at?->format('H:i A') }}</span>
                                    </time>
                                </div>
                            </article>
                        @empty
                            <div class="activity-empty">
                                <span class="material-symbols-outlined" aria-hidden="true">event_busy</span>
                                <p>Chua co hoat dong nao duoc ghi nhan.</p>
                            </div>
                        @endforelse
                    </div>

                    <footer class="activity-pagination">
                        <span>Showing {{ $activityTotal }} recent activities</span>
                    </footer>
                </section>
            </section>
        @elseif(($activeSection ?? 'dashboard') === 'settings')
            <section class="settings-page">
                <header class="settings-page-header">
                    <div>
                        <p>Settings</p>
                        <h1>Cài đặt tài khoản</h1>
                        <span>Quản lý thông tin đăng nhập và bảo mật cho tài khoản CRM.</span>
                    </div>
                </header>

                @if(session('settings_status'))
                    <div class="settings-alert success">{{ session('settings_status') }}</div>
                @endif

                @if($errors->any())
                    <div class="settings-alert danger">Vui lòng kiểm tra lại thông tin vừa nhập.</div>
                @endif

                <section class="settings-grid">
                    <article class="settings-card profile-settings-card">
                        <header>
                            <span class="settings-avatar">{{ strtoupper(substr($currentUser->name, 0, 1)) }}</span>
                            <div>
                                <h2>{{ $currentUser->name }}</h2>
                                <p>{{ $currentUser->email }}</p>
                            </div>
                        </header>
                        <dl>
                            <div>
                                <dt>Vai trò</dt>
                                <dd>{{ $currentUser->getRoleNames()->implode(', ') ?: 'User' }}</dd>
                            </div>
                            <div>
                                <dt>Trạng thái</dt>
                                <dd>{{ $currentUser->is_active ? 'Đang hoạt động' : 'Đã khóa' }}</dd>
                            </div>
                            <div>
                                <dt>Ngày tạo</dt>
                                <dd>{{ $currentUser->created_at?->format('d/m/Y') }}</dd>
                            </div>
                        </dl>
                    </article>

                    <article class="settings-card password-settings-card">
                        <header>
                            <div>
                                <h2>Đổi mật khẩu</h2>
                                <p>Mật khẩu mới sẽ được áp dụng cho lần đăng nhập tiếp theo.</p>
                            </div>
                        </header>
                        <form method="POST" action="{{ route('crm.settings.password.update') }}">
                            @csrf
                            @method('PATCH')

                            <label>
                                <span>Mật khẩu hiện tại</span>
                                <input type="password" name="current_password" autocomplete="current-password" required>
                                @error('current_password')
                                    <small>{{ $message }}</small>
                                @enderror
                            </label>

                            <label>
                                <span>Mật khẩu mới</span>
                                <input type="password" name="password" autocomplete="new-password" required>
                                @error('password')
                                    <small>{{ $message }}</small>
                                @enderror
                            </label>

                            <label>
                                <span>Nhập lại mật khẩu mới</span>
                                <input type="password" name="password_confirmation" autocomplete="new-password" required>
                            </label>

                            <button type="submit">Cập nhật mật khẩu</button>
                        </form>
                    </article>

                    <article class="settings-card settings-note-card">
                        <span class="material-symbols-outlined" aria-hidden="true">verified_user</span>
                        <h2>Bảo mật vận hành</h2>
                        <p>Admin vẫn đăng nhập bằng database như hiện tại. Nhân viên CSKH sử dụng tài khoản được cấp và không cần tự kết nối Facebook/Zalo.</p>
                    </article>
                </section>
            </section>
        @else
            <section class="today-dashboard-page">
                <header class="today-dashboard-header">
                    <div>
                        <h1>{{ $dashboardOverview['header']['title'] }}</h1>
                        <p>{{ $dashboardOverview['header']['subtitle'] }}</p>
                    </div>
                </header>

                <section class="today-kpi-grid">
                    @foreach($dashboardOverview['cards'] as $card)
                        <article class="today-kpi-card {{ $card['accent'] ? 'is-urgent' : '' }}">
                            <div>
                                <p>{{ $card['label'] }}</p>
                                <h2>{{ $card['value'] }}</h2>
                                <span class="{{ $card['tone'] }}">{{ $card['change'] }}</span>
                            </div>
                            <b>
                                <span class="material-symbols-outlined" aria-hidden="true">{{ $card['icon'] }}</span>
                            </b>
                        </article>
                    @endforeach
                </section>

                <section class="today-intent-grid">
                    @foreach($dashboardOverview['intentCards'] as $intent)
                        <article class="today-intent-card">
                            <b>
                                <span class="material-symbols-outlined" aria-hidden="true">{{ $intent['icon'] }}</span>
                            </b>
                            <div>
                                <p>{{ $intent['label'] }}</p>
                                <strong>{{ number_format($intent['value']) }}</strong>
                            </div>
                        </article>
                    @endforeach
                </section>

                <section class="today-chart-grid">
                    <article class="today-panel today-line-panel">
                        <header>
                            <h3>Hội thoại theo thời gian</h3>
                            <button type="button" aria-label="Tuy chon">
                                <span class="material-symbols-outlined" aria-hidden="true">more_horiz</span>
                            </button>
                        </header>
                        <div id="today-conversation-line" class="today-line-chart" aria-label="Hoi thoai theo thoi gian"></div>
                    </article>

                    <article class="today-panel today-source-panel">
                        <header>
                            <h3>Nguồn khách hàng</h3>
                        </header>
                        <div id="today-source-donut" class="today-donut-chart" aria-label="Nguon khach hang"></div>
                        <div class="today-source-legend">
                            @foreach($dashboardOverview['sources'] as $source)
                                <span>{{ $source['label'] }}</span>
                            @endforeach
                        </div>
                    </article>
                </section>
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
        var todayLineData = @json($dashboardOverview['timeSeries'] ?? []);
        var todaySourceData = @json($dashboardOverview['sources'] ?? []);

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

        if (activeSection === 'channels') {
            return;
        }

        if (activeSection !== 'dashboard' && activeSection !== 'reports') {
            return;
        }

        if (!todayLineData.length) {
            todayLineData = [{hour: '08:00', value: 0}];
        }

        Morris.Area({
            element: 'today-conversation-line',
            data: todayLineData,
            xkey: 'hour',
            ykeys: ['value'],
            labels: ['Hoi thoai'],
            parseTime: false,
            lineColors: ['#d70616'],
            pointFillColors: ['#ffffff'],
            pointStrokeColors: ['#d70616'],
            fillOpacity: 0.14,
            behaveLikeLine: true,
            gridTextColor: '#6f6f6f',
            gridLineColor: '#e5e7eb',
            resize: true,
            hideHover: 'auto'
        });

        if (todaySourceData.length) {
            Morris.Donut({
                element: 'today-source-donut',
                data: todaySourceData,
                colors: ['#2581ee', '#0f62fe'],
                resize: true,
                formatter: function (value) {
                    return value;
                }
            });
        } else {
            document.getElementById('today-source-donut').textContent = 'No conversation source data';
        }
    });
</script>
@endsection
