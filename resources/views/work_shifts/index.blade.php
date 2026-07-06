@extends('layouts.app', ['title' => 'Ca trực - CRM', 'bodyClass' => 'work-shifts-page'])

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/work-shifts.css') }}?v={{ filemtime(public_path('css/work-shifts.css')) }}">
@endpush

@push('scripts')
    <script src="{{ asset('js/work-shifts.js') }}?v={{ filemtime(public_path('js/work-shifts.js')) }}" defer></script>
@endpush

@section('content')
@php
    $overviewShifts = $shifts->take(3)->values();
    $staffShift = $selectedShift;
    $onlineCount = $staffMembers->where('is_active', true)->count();
    $busyCount = max(0, $staffMembers->count() - $onlineCount);
    $maxLoad = max(10, (int) $staffMembers->max('active_conversations_count'));
@endphp

<div class="crm-shell" data-crm-shell>
    @include('partials.crm.chrome')

    <main class="crm-main">
        @include('partials.crm.topbar')

        <section class="work-shifts-shell" data-work-shifts-page>
            <header class="work-shifts-hero">
                <div>
                    <h1>Quản lý ca trực và phân công</h1>
                    <p>Theo dõi ca trực, nhân sự đang nhận hội thoại và lịch sử phân bổ theo dữ liệu thật của CRM.</p>
                </div>
                <nav aria-label="Thao tác ca trực">
                    <button class="work-shifts-primary-action" type="button" data-open-shift-dialog="create-shift-dialog">
                        <span class="material-symbols-outlined" aria-hidden="true">add</span>
                        Tạo lịch trực mới
                    </button>
                </nav>
            </header>

            @if(session('status'))
                <p class="work-shifts-alert is-success">{{ session('status') }}</p>
            @endif

            @if($errors->any())
                <p class="work-shifts-alert is-error">{{ $errors->first() }}</p>
            @endif

            <nav class="work-shifts-tabs" aria-label="Điều hướng ca trực">
                <a class="active" href="#shift-overview">Ca trực gần nhất</a>
                <a href="#staff-on-shift">Nhân sự trong ca</a>
                <a href="#shift-list">Quản lý ca trực</a>
            </nav>

            <section class="shift-overview-grid" id="shift-overview">
                @forelse($overviewShifts as $shift)
                    @php
                        $isCurrent = $currentShift?->is($shift);
                        $isNext = ! $isCurrent && $nextShift?->is($shift);
                        $startsHour = (int) $shift->starts_at?->format('H');
                        $icon = $startsHour >= 20 || $startsHour < 6 ? 'nightlight' : ($startsHour >= 12 ? 'wb_twilight' : 'wb_sunny');
                    @endphp
                    <a class="shift-overview-card {{ $isCurrent ? 'is-current' : '' }} {{ $selectedShift?->is($shift) ? 'is-selected' : '' }}" href="{{ route('work-shifts.index', ['shift_id' => $shift->id, 'status' => $manageStatus]).'#staff-on-shift' }}">
                        <header>
                            <span class="material-symbols-outlined" aria-hidden="true">{{ $icon }}</span>
                            <div>
                                <h2>{{ $shift->name ?: 'Ca trực #'.$shift->id }}</h2>
                                <p>{{ $isCurrent ? 'Đang trực:' : ($isNext ? 'Sắp tới:' : ($shift->is_active ? 'Đang bật:' : 'Tạm tắt:')) }}</p>
                            </div>
                            <time>{{ $shift->starts_at?->format('H:i') }} - {{ $shift->ends_at?->format('H:i') }}</time>
                        </header>
                        <footer>
                            <span>{{ $isCurrent ? 'Đang diễn ra' : ($isNext ? 'Sắp tới' : ($shift->is_active ? 'Đang bật' : 'Tạm tắt')) }}</span>
                            <span>{{ $shift->agents_count ?? $shift->agents->count() }} nhân viên</span>
                        </footer>
                    </a>
                @empty
                    <article class="shift-overview-card is-empty">
                        <header>
                            <span class="material-symbols-outlined" aria-hidden="true">event_busy</span>
                            <div>
                                <h2>Chưa có lịch trực</h2>
                                <p>Tạo ca trực đầu tiên để bắt đầu phân bổ hội thoại.</p>
                            </div>
                        </header>
                    </article>
                @endforelse
            </section>

            <section class="shift-staff-panel" id="staff-on-shift">
                <header>
                    <div>
                        <h2>Nhân sự trong ca {{ $staffShift ? '('.$staffShift->name.')' : '' }}</h2>
                    </div>
                    <div class="shift-staff-stats">
                        <span><i class="online"></i>Đang hoạt động ({{ $onlineCount }})</span>
                        <span><i class="break"></i>Bận ({{ $busyCount }})</span>
                    </div>
                </header>
                <div class="shift-metric-strip">
                    <article>
                        <span>Khách đang đợi</span>
                        <strong>{{ $staffMetrics['waiting'] }}</strong>
                    </article>
                    <article>
                        <span>Đang xử lý</span>
                        <strong>{{ $staffMetrics['handling'] }}</strong>
                    </article>
                    <article>
                        <span>Đã hoàn tất</span>
                        <strong>{{ $staffMetrics['finished'] }}</strong>
                    </article>
                    <article>
                        <span>Tổng hội thoại</span>
                        <strong>{{ $staffMetrics['total'] }}</strong>
                    </article>
                </div>
                <div class="shift-staff-list">
                    @forelse($staffMembers as $agent)
                        @php
                            $load = (int) ($agent->active_conversations_count ?? 0);
                            $percent = min(100, ($load / $maxLoad) * 100);
                            $isBusy = $load >= 8;
                        @endphp
                        <article class="shift-staff-row">
                            <span class="shift-agent-avatar">{{ strtoupper(substr($agent->name, 0, 1)) }}<i class="{{ $isBusy ? 'break' : 'online' }}"></i></span>
                            <div class="shift-agent-info">
                                <h3>{{ $agent->name }}</h3>
                                <p>{{ $agent->email }}</p>
                            </div>
                            <div class="shift-load">
                                <div>
                                    <span>Tải hội thoại</span>
                                    <small class="{{ $isBusy ? 'danger' : '' }}">{{ $load }}/10</small>
                                </div>
                                <b><i style="width: {{ $percent }}%"></i></b>
                            </div>
                            <span class="shift-status-pill {{ $isBusy ? 'is-busy' : '' }}">{{ $isBusy ? 'Cần giảm tải' : 'Sẵn sàng' }}</span>
                        </article>
                    @empty
                        <article class="work-shifts-empty">
                            <h3>Chưa có nhân viên trong ca</h3>
                            <p>Mỗi ca cần tối thiểu 1 nhân viên CSKH để nhận khách mới.</p>
                        </article>
                    @endforelse
                </div>
                <a class="shift-view-all" href="#shift-list">Xem toàn bộ danh sách ({{ $agents->count() }} nhân viên)</a>
            </section>

            <section class="work-shifts-list" id="shift-list" aria-label="Danh sách ca trực">
                <header class="work-shifts-section-head">
                    <div>
                        <p>Quản lý</p>
                        <h2>{{ $managedShifts->count() }} ca trực</h2>
                    </div>
                    <div class="work-shifts-head-actions">
                        <form class="shift-inline-form" method="GET" action="{{ route('work-shifts.index') }}">
                            @if($staffShift)
                                <input type="hidden" name="shift_id" value="{{ $staffShift->id }}">
                            @endif
                            <label>
                                <span>Trạng thái</span>
                                <select name="status" onchange="this.form.submit()">
                                    <option value="all" @selected($manageStatus === 'all')>Tất cả</option>
                                    <option value="active" @selected($manageStatus === 'active')>Đang bật</option>
                                    <option value="inactive" @selected($manageStatus === 'inactive')>Tạm tắt</option>
                                    <option value="current" @selected($manageStatus === 'current')>Đang diễn ra</option>
                                </select>
                            </label>
                        </form>
                        <button class="work-shifts-primary-action" type="button" data-open-shift-dialog="create-shift-dialog">Tạo ca trực</button>
                    </div>
                </header>

                <div class="work-shifts-table">
                    @forelse($managedShifts as $shift)
                        <article class="work-shifts-card work-shifts-row">
                            <div class="work-shifts-row-main">
                                <span class="shift-agent-avatar">{{ strtoupper(substr($shift->name ?: 'C', 0, 1)) }}</span>
                                <div>
                                    <h3>{{ $shift->name ?: 'Ca trực #'.$shift->id }}</h3>
                                    <p>{{ $shift->starts_at?->format('d/m/Y H:i') }} - {{ $shift->ends_at?->format('d/m/Y H:i') }}</p>
                                </div>
                            </div>
                            <div class="work-shifts-row-agents">
                                <span>{{ $shift->agents_count ?? $shift->agents->count() }}/2 nhân viên</span>
                                <small>{{ (int) $shift->waiting_conversations_count }} đợi - {{ (int) $shift->handling_conversations_count }} đang xử lý - {{ (int) $shift->finished_conversations_count }} xong</small>
                            </div>
                            <span class="shift-status-pill {{ $shift->is_active ? '' : 'is-busy' }}">{{ $shift->is_active ? 'Đang bật' : 'Tạm tắt' }}</span>
                            <div class="work-shifts-row-actions">
                                <button type="button" data-open-shift-dialog="edit-shift-dialog-{{ $shift->id }}">Sửa</button>
                                <form class="work-shifts-delete" method="POST" action="{{ route('work-shifts.destroy', $shift) }}" data-delete-shift>
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit">Xóa</button>
                                </form>
                            </div>
                        </article>
                    @empty
                        <article class="work-shifts-empty">
                            <h3>Chưa có ca trực</h3>
                            <p>Tạo ca trực đầu tiên để nhân viên có thể nhận hội thoại đúng ca.</p>
                        </article>
                    @endforelse
                </div>
            </section>

            <dialog class="work-shift-dialog" id="create-shift-dialog" data-shift-dialog>
                <form class="work-shift-dialog-card work-shift-form" method="POST" action="{{ route('work-shifts.store') }}" data-shift-form>
                    @csrf
                    <header>
                        <div>
                            <p>Ca mới</p>
                            <h2>Tạo lịch trực</h2>
                        </div>
                        <button type="button" class="work-shift-dialog-close" data-close-shift-dialog aria-label="Đóng">x</button>
                    </header>
                    @include('work_shifts.form_fields', ['shift' => null, 'agents' => $createAgents])
                    <footer>
                        <small data-form-message>Chọn 1 đến 2 nhân viên cho mỗi ca trực.</small>
                        <button type="submit">Tạo ca trực</button>
                    </footer>
                </form>
            </dialog>

            @foreach($shifts as $shift)
                <dialog class="work-shift-dialog" id="edit-shift-dialog-{{ $shift->id }}" data-shift-dialog>
                    <form class="work-shift-dialog-card work-shift-form" method="POST" action="{{ route('work-shifts.update', $shift) }}" data-shift-form>
                        @csrf
                        @method('PUT')
                        <header>
                            <div>
                                <p>Chỉnh sửa</p>
                                <h2>{{ $shift->name ?: 'Ca trực #'.$shift->id }}</h2>
                            </div>
                            <button type="button" class="work-shift-dialog-close" data-close-shift-dialog aria-label="Đóng">x</button>
                        </header>
                        @include('work_shifts.form_fields', [
                            'shift' => $shift,
                            'agents' => $agents
                                ->filter(fn ($agent) => ! $busyAgentIds->contains($agent->id) || $shift->agents->contains('id', $agent->id))
                                ->values(),
                        ])
                        <footer>
                            <small data-form-message>Chọn 1 đến 2 nhân viên cho mỗi ca trực.</small>
                            <button type="submit">Lưu thay đổi</button>
                        </footer>
                    </form>
                </dialog>
            @endforeach
        </section>
    </main>
</div>
@endsection
