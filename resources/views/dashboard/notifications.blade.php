@php
    $notificationData = $notificationDashboard ?? [
        'notifications' => collect(),
        'types' => collect(),
        'typeCounts' => collect(),
        'summary' => ['total' => 0, 'unread' => 0, 'today' => 0, 'types' => 0],
        'filters' => ['status' => 'all', 'type' => 'all', 'keyword' => ''],
    ];
    $notifications = $notificationData['notifications'];
    $summary = $notificationData['summary'];
    $filters = $notificationData['filters'];
@endphp

<section class="notifications-page">
    <header class="notifications-header">
        <div>
            <p>Trung tâm thông báo</p>
            <h1>Thông báo</h1>
            <span>Theo dõi tin nhắn mới, phân công hội thoại và các cảnh báo cần xử lý.</span>
        </div>
        @if(($summary['unread'] ?? 0) > 0)
            <form method="POST" action="{{ route('crm.notifications.read-all') }}">
                @csrf
                @method('PATCH')
                <button type="submit">
                    <span class="material-symbols-outlined" aria-hidden="true">done_all</span>
                    Đánh dấu tất cả đã đọc
                </button>
            </form>
        @endif
    </header>

    @if(session('notification_status'))
        <div class="notifications-alert">{{ session('notification_status') }}</div>
    @endif

    <section class="notifications-filter-panel" aria-label="Bộ lọc thông báo">
        <form class="notifications-filter-form" method="GET" action="{{ route('crm.notifications') }}">
            <label>
                <span>Trạng thái</span>
                <select name="notification_status" onchange="this.form.submit()">
                    <option value="all" @selected(($filters['status'] ?? 'all') === 'all')>Tất cả trạng thái</option>
                    <option value="unread" @selected(($filters['status'] ?? 'all') === 'unread')>Chưa đọc</option>
                    <option value="read" @selected(($filters['status'] ?? 'all') === 'read')>Đã đọc</option>
                </select>
            </label>
            <label>
                <span>Loại thông báo</span>
                <select name="notification_type" onchange="this.form.submit()">
                    <option value="all" @selected(($filters['type'] ?? 'all') === 'all')>Tất cả loại</option>
                    @foreach($notificationData['types'] as $type)
                        <option value="{{ $type['key'] }}" @selected(($filters['type'] ?? 'all') === $type['key'])>{{ $type['label'] }}</option>
                    @endforeach
                </select>
            </label>
            <label class="notifications-keyword-field">
                <span>Từ khóa</span>
                <input type="search" name="notification_keyword" value="{{ $filters['keyword'] ?? '' }}" placeholder="Nội dung, ID hội thoại, khách hàng...">
            </label>
            <button type="submit">Tìm</button>
        </form>
    </section>

    <section class="notifications-summary-grid" aria-label="Tổng quan thông báo">
        <article>
            <span class="material-symbols-outlined" aria-hidden="true">notifications</span>
            <p>Tổng thông báo</p>
            <strong>{{ number_format($summary['total'] ?? 0) }}</strong>
        </article>
        <article>
            <span class="material-symbols-outlined" aria-hidden="true">mark_email_unread</span>
            <p>Chưa đọc</p>
            <strong>{{ number_format($summary['unread'] ?? 0) }}</strong>
        </article>
        <article>
            <span class="material-symbols-outlined" aria-hidden="true">today</span>
            <p>Hôm nay</p>
            <strong>{{ number_format($summary['today'] ?? 0) }}</strong>
        </article>
        <article>
            <span class="material-symbols-outlined" aria-hidden="true">category</span>
            <p>Loại thông báo</p>
            <strong>{{ number_format($summary['types'] ?? 0) }}</strong>
        </article>
    </section>

    <section class="notifications-layout">
        <article class="notifications-list-panel">
            <header>
                <h2>Danh sách thông báo</h2>
            </header>
            <div class="notifications-list">
                @forelse($notifications as $notification)
                    @php
                        $data = $notification->data ?? [];
                        $type = (string) data_get($data, 'type', 'system');
                        $typeLabel = match ($type) {
                            'new_message' => 'Tin nhắn mới',
                            'conversation_assigned' => 'Phân công hội thoại',
                            'conversation_waiting' => 'Khách đang đợi',
                            default => \Illuminate\Support\Str::of($type)->replace(['_', '-'], ' ')->headline(),
                        };
                        $title = match ($type) {
                            'new_message' => 'Có tin nhắn mới từ '.(data_get($data, 'sender_name') ?: 'khách hàng'),
                            'conversation_assigned' => 'Bạn được phân công một hội thoại',
                            default => 'Thông báo hệ thống',
                        };
                        $description = match ($type) {
                            'new_message' => 'Hội thoại #'.data_get($data, 'conversation_id', '-').' vừa có tin nhắn mới.',
                            'conversation_assigned' => 'Hội thoại #'.data_get($data, 'conversation_id', '-').' cần được theo dõi và xử lý.',
                            default => json_encode($data, JSON_UNESCAPED_UNICODE),
                        };
                    @endphp
                    <article class="notification-item {{ $notification->read_at ? 'is-read' : 'is-unread' }}">
                        <span class="notification-icon" data-type="{{ $type }}">
                            <span class="material-symbols-outlined" aria-hidden="true">
                                {{ $type === 'new_message' ? 'chat' : ($type === 'conversation_assigned' ? 'assignment_ind' : 'notifications') }}
                            </span>
                        </span>
                        <div class="notification-body">
                            <header>
                                <div>
                                    <p>{{ $typeLabel }}</p>
                                    <h3>{{ $title }}</h3>
                                </div>
                                <time>{{ $notification->created_at?->diffForHumans() }}</time>
                            </header>
                            <p>{{ $description }}</p>
                            <div class="notification-meta">
                                <span>{{ $notification->read_at ? 'Đã đọc' : 'Chưa đọc' }}</span>
                                @if(data_get($data, 'conversation_id'))
                                    <a href="{{ route('crm.conversations.show', data_get($data, 'conversation_id')) }}">Mở hội thoại</a>
                                @endif
                            </div>
                        </div>
                        @unless($notification->read_at)
                            <form method="POST" action="{{ route('crm.notifications.read', $notification->id) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" aria-label="Đánh dấu đã đọc">
                                    <span class="material-symbols-outlined" aria-hidden="true">done</span>
                                </button>
                            </form>
                        @endunless
                    </article>
                @empty
                    <div class="notifications-empty">
                        <span class="material-symbols-outlined" aria-hidden="true">notifications_off</span>
                        <p>Chưa có thông báo nào phù hợp với bộ lọc.</p>
                    </div>
                @endforelse
            </div>
        </article>

        <aside class="notifications-type-panel">
            <h2>Phân loại</h2>
            @forelse($notificationData['typeCounts'] as $type)
                <div class="notification-type-row">
                    <span class="notification-type-dot" data-type="{{ $type['key'] }}"></span>
                    <span>{{ $type['label'] }}</span>
                    <strong>{{ number_format($type['count']) }}</strong>
                </div>
            @empty
                <p>Chưa có dữ liệu phân loại.</p>
            @endforelse
        </aside>
    </section>
</section>
