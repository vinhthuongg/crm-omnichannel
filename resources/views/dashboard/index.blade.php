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
        @elseif(($activeSection ?? 'dashboard') === 'activity')
            @php
                $activityTotal = $activityLogs->count();
                $activityToday = $activityLogs->filter(fn ($log) => $log->created_at?->isToday())->count();
                $activityUsers = $activityLogs->pluck('user_id')->filter()->unique()->count();
                $latestActivity = $activityLogs->first()?->created_at?->diffForHumans() ?? 'Chua co du lieu';
            @endphp

            <section class="activity-log-page">
                <header class="activity-page-header">
                    <div>
                        <p>Activity Log</p>
                        <h1>Nhật ký hoạt động</h1>
                        <span>Theo dõi thao tác của nhân viên, hệ thống và các luồng chăm sóc khách hàng.</span>
                    </div>
                    <a href="{{ route('crm.conversations') }}">
                        Xem hội thoại
                    </a>
                </header>

                <section class="activity-summary-grid">
                    <article>
                        <span class="material-symbols-outlined" aria-hidden="true">history</span>
                        <p>Tổng bản ghi gần đây</p>
                        <strong>{{ number_format($activityTotal) }}</strong>
                    </article>
                    <article>
                        <span class="material-symbols-outlined" aria-hidden="true">today</span>
                        <p>Hoạt động hôm nay</p>
                        <strong>{{ number_format($activityToday) }}</strong>
                    </article>
                    <article>
                        <span class="material-symbols-outlined" aria-hidden="true">groups</span>
                        <p>Nhân viên liên quan</p>
                        <strong>{{ number_format($activityUsers) }}</strong>
                    </article>
                    <article>
                        <span class="material-symbols-outlined" aria-hidden="true">schedule</span>
                        <p>Cập nhật mới nhất</p>
                        <strong>{{ $latestActivity }}</strong>
                    </article>
                </section>

                <section class="activity-layout">
                    <article class="activity-feed-panel">
                        <header>
                            <div>
                                <h2>Dòng hoạt động</h2>
                                <p>Các thao tác mới nhất trong phạm vi dữ liệu được phép xem.</p>
                            </div>
                        </header>

                        <div class="activity-feed">
                            @forelse($activityLogs as $log)
                                @php
                                    $metadata = collect($log->metadata ?? []);
                                    $actionLabel = \Illuminate\Support\Str::headline(str_replace(['.', '_', '-'], ' ', (string) $log->action));
                                    $subjectLabel = $log->subject_type ? class_basename($log->subject_type).' #'.$log->subject_id : 'He thong';
                                @endphp
                                <article class="activity-feed-item">
                                    <span class="activity-feed-icon">
                                        <span class="material-symbols-outlined" aria-hidden="true">bolt</span>
                                    </span>
                                    <div>
                                        <header>
                                            <h3>{{ $actionLabel ?: 'Hoat dong' }}</h3>
                                            <time>{{ $log->created_at?->format('H:i d/m/Y') }}</time>
                                        </header>
                                        <p>
                                            {{ $log->user?->name ?? 'System' }}
                                            <span>•</span>
                                            {{ $subjectLabel }}
                                        </p>
                                        @if($metadata->isNotEmpty())
                                            <dl>
                                                @foreach($metadata->take(3) as $key => $value)
                                                    <div>
                                                        <dt>{{ \Illuminate\Support\Str::headline((string) $key) }}</dt>
                                                        <dd>{{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE) }}</dd>
                                                    </div>
                                                @endforeach
                                            </dl>
                                        @endif
                                    </div>
                                </article>
                            @empty
                                <div class="activity-empty">
                                    <span class="material-symbols-outlined" aria-hidden="true">event_busy</span>
                                    <p>Chưa có hoạt động nào được ghi nhận.</p>
                                </div>
                            @endforelse
                        </div>
                    </article>

                    <aside class="activity-side-panel">
                        <h2>Bộ lọc nhanh</h2>
                        <a href="{{ route('crm.activity') }}">Tất cả hoạt động</a>
                        <a href="{{ route('crm.conversations') }}">Hoạt động hội thoại</a>
                        <a href="{{ route('crm.customers') }}">Hoạt động khách hàng</a>
                    </aside>
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
                    <div class="today-dashboard-actions">
                        <button type="button">
                            Hôm nay
                            <span class="material-symbols-outlined" aria-hidden="true">expand_more</span>
                        </button>
                        <a href="{{ route('crm.reports') }}">
                            <span class="material-symbols-outlined" aria-hidden="true">download</span>
                            Xuất BC
                        </a>
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

        if (!todaySourceData.length) {
            todaySourceData = [{label: 'Chua co du lieu', value: 1}];
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

        Morris.Donut({
            element: 'today-source-donut',
            data: todaySourceData,
            colors: ['#2581ee', '#0f62fe', '#000000', '#ee0d20'],
            resize: true,
            formatter: function (value) {
                return value;
            }
        });
    });
</script>
@endsection
