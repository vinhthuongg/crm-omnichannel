@php
    $activityData = $activityDashboard ?? [
        'logs' => $activityLogs,
        'agents' => collect(),
        'types' => collect(),
        'agentCounts' => collect(),
        'typeCounts' => collect(),
        'summary' => [
            'total' => $activityLogs->count(),
            'today' => $activityLogs->filter(fn ($log) => $log->created_at?->isToday())->count(),
            'agents' => $activityLogs->pluck('user_id')->filter()->unique()->count(),
            'types' => 0,
        ],
        'filters' => ['agent' => 'all', 'type' => 'all', 'keyword' => ''],
    ];
    $logs = $activityData['logs'];
    $summary = $activityData['summary'];
    $activityFilters = $activityData['filters'];
@endphp

<section class="activity-log-page">
    <header class="activity-page-header">
        <div>
            <p>Hoạt động CRM</p>
            <h1>Nhật ký hoạt động</h1>
            <span>Phân loại theo nhân viên, loại hoạt động và các thao tác phát sinh trong CRM.</span>
        </div>
    </header>

    <section class="activity-filter-panel" aria-label="Bộ lọc hoạt động">
        <form class="activity-filter-form" method="GET" action="{{ route('crm.activity') }}">
            <label>
                <span>Nhân viên</span>
                <select name="activity_agent" onchange="this.form.submit()">
                    <option value="all" @selected(($activityFilters['agent'] ?? 'all') === 'all')>Tất cả nhân viên</option>
                    @foreach($activityData['agents'] as $agent)
                        <option value="{{ $agent['id'] }}" @selected((string) ($activityFilters['agent'] ?? 'all') === (string) $agent['id'])>{{ $agent['name'] }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Loại hoạt động</span>
                <select name="activity_type" onchange="this.form.submit()">
                    <option value="all" @selected(($activityFilters['type'] ?? 'all') === 'all')>Tất cả loại</option>
                    @foreach($activityData['types'] as $type)
                        <option value="{{ $type['key'] }}" @selected(($activityFilters['type'] ?? 'all') === $type['key'])>{{ $type['label'] }}</option>
                    @endforeach
                </select>
            </label>
            <label class="activity-keyword-field">
                <span>Từ khóa</span>
                <input type="search" name="activity_keyword" value="{{ $activityFilters['keyword'] ?? '' }}" placeholder="Tên nhân viên, hành động, ID...">
            </label>
            <button type="submit">Tìm</button>
        </form>
    </section>

    <section class="activity-summary-grid" aria-label="Tổng quan hoạt động">
        <article>
            <span class="material-symbols-outlined" aria-hidden="true">history</span>
            <p>Tổng hoạt động</p>
            <strong>{{ number_format($summary['total'] ?? 0) }}</strong>
        </article>
        <article>
            <span class="material-symbols-outlined" aria-hidden="true">today</span>
            <p>Hoạt động hôm nay</p>
            <strong>{{ number_format($summary['today'] ?? 0) }}</strong>
        </article>
        <article>
            <span class="material-symbols-outlined" aria-hidden="true">support_agent</span>
            <p>Nhân viên có hoạt động</p>
            <strong>{{ number_format($summary['agents'] ?? 0) }}</strong>
        </article>
        <article>
            <span class="material-symbols-outlined" aria-hidden="true">category</span>
            <p>Loại hoạt động</p>
            <strong>{{ number_format($summary['types'] ?? 0) }}</strong>
        </article>
    </section>

    <section class="activity-classification-grid" aria-label="Phân loại hoạt động">
        <article class="activity-classification-card">
            <header>
                <h2>Theo nhân viên</h2>
            </header>
            <div class="activity-breakdown-list">
                @forelse($activityData['agentCounts'] as $agent)
                    <div class="activity-breakdown-row">
                        <span class="activity-person-avatar">{{ $agent['initial'] }}</span>
                        <span>{{ $agent['name'] }}</span>
                        <strong>{{ number_format($agent['count']) }}</strong>
                    </div>
                @empty
                    <p>Chưa có dữ liệu nhân viên.</p>
                @endforelse
            </div>
        </article>
        <article class="activity-classification-card">
            <header>
                <h2>Theo loại hoạt động</h2>
            </header>
            <div class="activity-breakdown-list">
                @forelse($activityData['typeCounts'] as $type)
                    <div class="activity-breakdown-row">
                        <span class="activity-type-dot" data-type="{{ $type['key'] }}"></span>
                        <span>{{ $type['label'] }}</span>
                        <strong>{{ number_format($type['count']) }}</strong>
                    </div>
                @empty
                    <p>Chưa có dữ liệu loại hoạt động.</p>
                @endforelse
            </div>
        </article>
    </section>

    <section class="activity-feed-panel">
        <header>
            <h2>Hoạt động gần đây</h2>
        </header>

        <div class="activity-feed">
            @forelse($logs as $log)
                @php
                    $metadata = collect($log->metadata ?? []);
                    $action = (string) $log->action;
                    $activityType = str_contains($action, '.') ? \Illuminate\Support\Str::before($action, '.') : 'other';
                    $actionLabel = match ($action) {
                        'conversation.claimed' => 'nhận xử lý hội thoại',
                        'conversation.released' => 'trả hội thoại về hàng đợi',
                        'conversation.assigned' => 'phân công hội thoại',
                        'conversation.transferred' => 'chuyển hội thoại',
                        'conversation.tagged' => 'cập nhật tag hội thoại',
                        'conversation.resolved' => 'đánh dấu đã xử lý',
                        'conversation.reopened' => 'mở lại hội thoại',
                        'message.sent' => 'gửi tin nhắn',
                        default => \Illuminate\Support\Str::of($action)->replace(['.', '_', '-'], ' ')->headline()->lower(),
                    };
                    $typeLabel = match ((string) $activityType) {
                        'conversation' => 'Hội thoại',
                        'message' => 'Tin nhắn',
                        'customer' => 'Khách hàng',
                        'facebook' => 'Facebook',
                        default => (! $log->user_id || str_contains($action, 'system') || str_contains($action, 'auto')) ? 'Hệ thống' : 'Khác',
                    };
                    $actor = $log->user?->name ?: 'Hệ thống';
                    $subjectLabel = $log->subject_type ? class_basename($log->subject_type).' #'.$log->subject_id : 'CRM';
                    $isSystem = ! $log->user_id || str_contains($action, 'system') || str_contains($action, 'auto');
                    $channel = strtolower((string) ($metadata->get('channel') ?: $metadata->get('source') ?: 'system'));
                    $detail = $metadata->isNotEmpty()
                        ? $metadata->map(fn ($value, $key) => \Illuminate\Support\Str::headline((string) $key).': '.(is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE)))->take(2)->implode(' | ')
                        : '# '.$subjectLabel;
                @endphp
                <article class="activity-feed-item {{ $isSystem ? 'is-system' : '' }}">
                    <span class="activity-feed-icon">
                        @if($isSystem)
                            <span class="material-symbols-outlined" aria-hidden="true">settings_suggest</span>
                        @else
                            {{ mb_strtoupper(mb_substr($actor, 0, 1)) }}
                        @endif
                    </span>
                    <div class="activity-card">
                        <div class="activity-card-main">
                            <p>
                                <strong>{{ $actor }}</strong>
                                <span>{{ $actionLabel }}</span>
                                <a href="{{ route('crm.activity') }}">{{ $subjectLabel }}</a>
                            </p>
                            <div class="activity-meta-line">
                                <span>{{ $detail }}</span>
                                <b class="activity-badge {{ strtolower((string) $activityType) }}">{{ $typeLabel }}</b>
                                @if(in_array($channel, ['facebook', 'zalo'], true))
                                    <b class="activity-badge {{ $channel }}">{{ ucfirst($channel) }}</b>
                                @endif
                            </div>
                        </div>
                        <time>
                            <strong>{{ $log->created_at?->isToday() ? $log->created_at?->format('H:i') : $log->created_at?->diffForHumans() }}</strong>
                            <span>{{ $log->created_at?->isToday() ? 'Hôm nay' : $log->created_at?->format('d/m/Y') }}</span>
                        </time>
                    </div>
                </article>
            @empty
                <div class="activity-empty">
                    <span class="material-symbols-outlined" aria-hidden="true">event_busy</span>
                    <p>Chưa có hoạt động nào phù hợp với bộ lọc.</p>
                </div>
            @endforelse
        </div>

        <footer class="activity-pagination">
            <span>Hiển thị {{ $logs->count() }} trong {{ number_format($summary['total'] ?? 0) }} hoạt động phù hợp</span>
        </footer>
    </section>
</section>
