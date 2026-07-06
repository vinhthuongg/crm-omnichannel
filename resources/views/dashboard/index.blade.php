@extends('layouts.app', ['title' => $sectionTitle . ' - CRM'])

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/crm/channels.css') }}?v={{ filemtime(public_path('css/crm/channels.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/crm/agents.css') }}?v={{ filemtime(public_path('css/crm/agents.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/crm/activity-settings.css') }}?v={{ filemtime(public_path('css/crm/activity-settings.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/crm/dashboard/dashboard.css') }}?v={{ filemtime(public_path('css/crm/dashboard/dashboard.css')) }}">
@endpush

@section('content')
<div class="crm-shell" data-crm-shell>
    @include('partials.crm.chrome')

    <main class="crm-main">
        @include('partials.crm.topbar')

        @if(($activeSection ?? 'dashboard') === 'channels')
            <section class="channel-connections-page">
                <header class="channel-connections-header">
                    <div>
                        <h1>{{ $channelManagement['title'] }}</h1>
                        <p>{{ $channelManagement['subtitle'] }}</p>
                    </div>
                    <a class="channel-add-button" href="{{ $channelManagement['add_url'] }}" aria-label="Thêm kết nối" title="Thêm kết nối">
                        <span class="material-symbols-outlined" aria-hidden="true">add</span>
                    </a>
                </header>

                @if($channelManagement['cards']->isEmpty())
                    <section class="channel-empty-state">
                        <span class="material-symbols-outlined" aria-hidden="true">hub</span>
                        <h2>Chưa có kênh nào được kết nối</h2>
                        <p>Nhấn nút cộng ở góc phải để kết nối Facebook Page đầu tiên cho CRM.</p>
                    </section>
                @else
                <section class="channel-connection-grid">
                    @foreach($channelManagement['cards'] as $channel)
                        <article class="channel-connection-card channel-connection-card-{{ $channel['key'] }}">
                            <header>
                                <span class="channel-connection-icon">
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
                            </header>

                            <dl>
                                <div>
                                    <dt>Kênh</dt>
                                    <dd>{{ $channel['channel'] }}</dd>
                                </div>
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
                                        <span class="channel-connection-badge {{ $channel['webhook_tone'] }}">{{ $channel['webhook'] }}</span>
                                    </dd>
                                </div>
                            </dl>

                            <footer>
                                @if($channel['sync_url'])
                                    <form method="POST" action="{{ $channel['sync_url'] }}">
                                        @csrf
                                        <button type="submit" class="channel-connection-primary">
                                            <span class="material-symbols-outlined" aria-hidden="true">sync</span>
                                            Đồng bộ
                                        </button>
                                    </form>
                                @endif
                            </footer>
                        </article>
                    @endforeach
                </section>
                @endif
            </section>
        @elseif(($activeSection ?? 'dashboard') === 'agents')
            <section class="agent-report-page">
                <header class="agent-page-header">
                    <div>
                        <h1>Hiệu suất nhân viên</h1>
                        <p>Dữ liệu được tổng hợp từ các cuộc hội thoại hiện có trong hệ thống</p>
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
                            <h3>Hội thoại xử lý theo nhân viên</h3>
                            <button type="button" aria-label="Tùy chọn">
                                <span class="material-symbols-outlined" aria-hidden="true">more_vert</span>
                            </button>
                        </header>
                        <div id="agent-handled-bar" class="agent-chart" aria-label="Hội thoại xử lý theo nhân viên"></div>
                    </article>

                    <article class="agent-panel">
                        <header>
                            <h3>Tốc độ phản hồi trung bình (phút)</h3>
                            <span class="agent-chart-legend"><i></i>Toàn đội</span>
                        </header>
                        <div id="agent-response-line" class="agent-chart" aria-label="Tốc độ phản hồi trung bình"></div>
                    </article>
                </section>

                <section class="agent-table-panel">
                    <header>
                        <h3>Chi tiết hiệu suất nhân viên</h3>
                        <label>
                            <span class="material-symbols-outlined" aria-hidden="true">search</span>
                            <input type="search" placeholder="Tìm nhân viên">
                        </label>
                    </header>
                    <div class="agent-table-wrap">
                        <table class="agent-performance-table">
                            <thead>
                                <tr>
                                    <th>Nhân viên</th>
                                    <th>Tổng hội thoại</th>
                                    <th>Đã xử lý</th>
                                    <th>Đang xử lý</th>
                                    <th>TG phản hồi trung bình</th>
                                    <th>SĐT thu thập</th>
                                    <th>Đánh giá</th>
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
                                        <td>{{ number_format($agent['avg_response_minutes'], 1) }} phút</td>
                                        <td>{{ number_format($agent['phone_collected']) }}</td>
                                        <td><span class="agent-rating">{{ $agent['rating'] }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <footer>
                        <span>Hiển thị {{ count($agentDashboard['rows']) }} nhân viên</span>
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
                        <h1>Nhật ký hoạt động</h1>
                        <span>Theo dõi thao tác hệ thống, phân công và tương tác khách hàng.</span>
                    </div>
                </header>

                <section class="activity-filter-panel" aria-label="Bộ lọc hoạt động">
                    <article>
                        <span>Tổng</span>
                        <strong>{{ number_format($activityTotal) }}</strong>
                    </article>
                    <article>
                        <span>Hôm nay</span>
                        <strong>{{ number_format($activityToday) }}</strong>
                    </article>
                    <article>
                        <span>Nhân viên</span>
                        <strong>{{ number_format($activityUsers) }}</strong>
                    </article>
                </section>

                <section class="activity-feed-panel">
                    <header>
                        <h2>Hoạt động gần đây</h2>
                    </header>

                    <div class="activity-feed">
                        @forelse($activityLogs as $log)
                            @php
                                $metadata = collect($log->metadata ?? []);
                                $action = (string) $log->action;
                                $actionLabel = \Illuminate\Support\Str::headline(str_replace(['.', '_', '-'], ' ', $action));
                                $actor = $log->user?->name ?: 'Hệ thống';
                                $subjectLabel = $log->subject_type ? class_basename($log->subject_type).' #'.$log->subject_id : 'CRM';
                                $isSystem = ! $log->user_id || str_contains($action, 'system') || str_contains($action, 'auto');
                                $channel = strtolower((string) ($metadata->get('channel') ?: $metadata->get('source') ?: 'system'));
                                $badge = in_array($channel, ['facebook', 'zalo'], true) ? ucfirst($channel) : 'Hệ thống';
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
                                            <span>{{ strtolower($actionLabel ?: 'cập nhật hoạt động') }}</span>
                                            <a href="{{ route('crm.activity') }}">{{ $subjectLabel }}</a>
                                        </p>
                                        <div class="activity-meta-line">
                                            <span>{{ $detail }}</span>
                                            <b class="activity-badge {{ strtolower($channel) }}">{{ $badge }}</b>
                                        </div>
                                    </div>
                                    <time>
                                        <strong>{{ $log->created_at?->isToday() ? $log->created_at?->format('H:i') : $log->created_at?->diffForHumans() }}</strong>
                                        <span>{{ $log->created_at?->isToday() ? 'HÔM NAY' : $log->created_at?->format('H:i') }}</span>
                                    </time>
                                </div>
                            </article>
                        @empty
                            <div class="activity-empty">
                                <span class="material-symbols-outlined" aria-hidden="true">event_busy</span>
                                <p>Chưa có hoạt động nào ghi nhận</p>
                            </div>
                        @endforelse
                    </div>

                    <footer class="activity-pagination">
                        <span>Hiển thị {{ $activityTotal }} hoạt động gần đây</span>
                    </footer>
                </section>
            </section>
        @elseif(($activeSection ?? 'dashboard') === 'settings')
            <section class="settings-page">
                <header class="settings-page-header">
                    <div>
                        <p>Cài đặt</p>
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
                                <dd>{{ $currentUser->getRoleNames()->implode(', ') ?: 'Người dùng' }}</dd>
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
                        <span class="today-dashboard-eyebrow">Dashboard</span>
                        <h1>{{ $dashboardOverview['header']['title'] }}</h1>
                        <p>{{ $dashboardOverview['header']['subtitle'] }}</p>
                    </div>
                    <span class="today-dashboard-status">
                        <i></i>
                        Đang cập nhật theo dữ liệu thật
                    </span>
                </header>

                <section class="today-metric-grid">
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

                    @foreach($dashboardOverview['intentCards'] as $intent)
                        <article class="today-kpi-card">
                            <b>
                                <span class="material-symbols-outlined" aria-hidden="true">{{ $intent['icon'] }}</span>
                            </b>
                            <div>
                                <p>{{ $intent['label'] }}</p>
                                <h2>{{ number_format($intent['value']) }}</h2>
                            </div>
                        </article>
                    @endforeach
                </section>

                <section class="today-chart-grid">
                    <article class="today-panel today-line-panel">
                        <header>
                            <h3>Hội thoại theo thời gian</h3>
                        </header>
                        <div id="today-conversation-line" class="today-line-chart" aria-label="Hội thoại theo thời gian"></div>
                    </article>

                    <article class="today-panel today-source-panel">
                        <header>
                            <h3>Nguồn khách hàng</h3>
                        </header>
                        <div id="today-source-donut" class="today-donut-chart" aria-label="Nguồn khách hàng"></div>
                        <div class="today-source-legend">
                            @foreach($dashboardOverview['sources'] as $source)
                                <span>{{ $source['label'] }}</span>
                            @endforeach
                        </div>
                    </article>
                </section>

                @include('dashboard.agent-performance')
            </section>
        @endif
    </main>
</div>

<script>
    window.CrmDashboardData = {
        activeSection: @json($activeSection ?? 'dashboard'),
        agentBar: @json($agentDashboard['bar'] ?? []),
        agentLine: @json($agentDashboard['responseLine'] ?? []),
        todayLine: @json($dashboardOverview['timeSeries'] ?? []),
        todaySources: @json($dashboardOverview['sources'] ?? []),
    };
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js" defer></script>
<script src="{{ asset('js/crm/dashboard/dashboard.js') }}?v={{ filemtime(public_path('js/crm/dashboard/dashboard.js')) }}" defer></script>
@endsection
