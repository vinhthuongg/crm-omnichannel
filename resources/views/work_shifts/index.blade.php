@extends('layouts.app', ['title' => 'Ca truc - CRM', 'bodyClass' => 'work-shifts-page'])

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/work-shifts.css') }}?v={{ filemtime(public_path('css/work-shifts.css')) }}">
@endpush

@push('scripts')
    <script src="{{ asset('js/work-shifts.js') }}?v={{ filemtime(public_path('js/work-shifts.js')) }}" defer></script>
@endpush

@section('content')
@php
    $overviewShifts = $shifts->take(3)->values();
    $staffShift = $currentShift ?: $nextShift;
    $staffMembers = $staffShift?->agents ?? $agents->take(3);
    $onlineCount = $staffMembers->where('is_active', true)->count();
    $breakCount = max(0, $staffMembers->count() - $onlineCount);
    $maxLoad = max(10, (int) $staffMembers->max('active_conversations_count'));
@endphp

<div class="crm-shell" data-crm-shell>
    @include('partials.crm.chrome')

    <main class="crm-main">
        @include('partials.crm.topbar')

        <section class="work-shifts-shell" data-work-shifts-page>
            <header class="work-shifts-hero">
                <div>
                    <h1>Quản lý ca trực và Phân công</h1>
                    <p>Theo dõi lịch trực và cấu hình quy tắc phân bổ hội thoại tự động cho nhóm CSKH.</p>
                </div>
                <nav aria-label="Thao tac ca truc">
                    <a class="work-shifts-secondary-action" href="#shift-list">
                        <span class="material-symbols-outlined" aria-hidden="true">manage_accounts</span>
                        Thiết lập phân công
                    </a>
                    <a class="work-shifts-primary-action" href="#create-shift">
                        <span class="material-symbols-outlined" aria-hidden="true">add</span>
                        Tạo lịch trực mới
                    </a>
                </nav>
            </header>

            <nav class="work-shifts-tabs" aria-label="Quan ly ca truc">
                <a class="active" href="#weekly-schedule">Lịch trực tuần này</a>
                <a href="#staff-on-shift">Danh sách nhân viên</a>
                <a href="#create-shift">Quy tắc phân công</a>
                <a href="#shift-list">Lịch sử ca trực</a>
            </nav>

            @if(session('status'))
                <p class="work-shifts-alert is-success">{{ session('status') }}</p>
            @endif

            @if($errors->any())
                <p class="work-shifts-alert is-error">{{ $errors->first() }}</p>
            @endif

            <section class="shift-overview-grid" id="weekly-schedule">
                @forelse($overviewShifts as $shift)
                    @php
                        $isCurrent = $currentShift?->is($shift);
                        $startsHour = (int) $shift->starts_at?->format('H');
                        $icon = $startsHour >= 20 || $startsHour < 6 ? 'nightlight' : ($startsHour >= 12 ? 'wb_twilight' : 'wb_sunny');
                    @endphp
                    <article class="shift-overview-card {{ $isCurrent ? 'is-current' : '' }}">
                        <header>
                            <span class="material-symbols-outlined" aria-hidden="true">{{ $icon }}</span>
                            <div>
                                <h2>{{ $shift->name ?: 'Ca trực #'.$shift->id }}</h2>
                                <p>{{ $isCurrent ? 'Đang diễn ra' : ($shift->starts_at && $shift->starts_at->isFuture() ? 'Sắp tới' : 'Đã lên lịch') }}</p>
                            </div>
                            <time>{{ $shift->starts_at?->format('H:i') }} - {{ $shift->ends_at?->format('H:i') }}</time>
                        </header>
                        <footer>
                            <span>Đang trực:</span>
                            <strong>{{ $shift->agents->count() }} nhân viên</strong>
                        </footer>
                    </article>
                @empty
                    <article class="shift-overview-card">
                        <header>
                            <span class="material-symbols-outlined" aria-hidden="true">event_busy</span>
                            <div>
                                <h2>Chưa có lịch trực</h2>
                                <p>Tạo ca đầu tiên để bắt đầu phân bổ hội thoại.</p>
                            </div>
                        </header>
                    </article>
                @endforelse
            </section>

            <section class="shift-staff-panel" id="staff-on-shift">
                <header>
                    <h2>Nhân sự ca hiện tại{{ $staffShift ? ' ('.$staffShift->name.')' : '' }}</h2>
                    <div>
                        <span><i class="online"></i>Online ({{ $onlineCount }})</span>
                        <span><i class="break"></i>Bận ({{ $breakCount }})</span>
                    </div>
                </header>

                <div class="shift-staff-list">
                    @forelse($staffMembers as $agent)
                        @php
                            $load = (int) ($agent->active_conversations_count ?? 0);
                            $percent = min(100, ($load / $maxLoad) * 100);
                            $isBusy = $load >= 8;
                        @endphp
                        <article class="shift-staff-row">
                            <span class="shift-agent-avatar">
                                {{ strtoupper(substr($agent->name, 0, 1)) }}
                                <i class="{{ $isBusy ? 'break' : 'online' }}"></i>
                            </span>
                            <div class="shift-agent-info">
                                <h3>{{ $agent->name }}</h3>
                                <p>{{ $agent->hasRole('CSKH') ? 'CSKH Cao cấp' : 'CSKH Mới' }}</p>
                            </div>
                            <div class="shift-load">
                                <div>
                                    <span>Tải công việc</span>
                                    <strong class="{{ $isBusy ? 'danger' : '' }}">{{ $load }}/10 hội thoại</strong>
                                </div>
                                <b><i style="width: {{ $percent }}%"></i></b>
                            </div>
                            <button type="button" disabled>{{ $isBusy ? 'Đã quá tải' : 'Tạm dừng nhận' }}</button>
                        </article>
                    @empty
                        <article class="work-shifts-empty">
                            <h3>Chưa có nhân viên trong ca</h3>
                            <p>Chọn đúng 2 nhân viên khi tạo hoặc sửa lịch trực.</p>
                        </article>
                    @endforelse
                </div>
            </section>

            <section class="work-shifts-management">
                <article class="work-shifts-card work-shifts-create" id="create-shift">
                    <header>
                        <p>Ca mới</p>
                        <h2>Tạo lịch trực</h2>
                    </header>

                    <form class="work-shift-form" method="POST" action="{{ route('work-shifts.store') }}" data-shift-form>
                        @csrf
                        <label>
                            <span>Tên ca</span>
                            <input name="name" placeholder="Ví dụ: Ca sáng 08:00 - 16:00" value="{{ old('name') }}">
                        </label>

                        <div class="work-shifts-fields">
                            <label>
                                <span>Bắt đầu</span>
                                <input type="datetime-local" name="starts_at" value="{{ old('starts_at') }}" required>
                            </label>
                            <label>
                                <span>Kết thúc</span>
                                <input type="datetime-local" name="ends_at" value="{{ old('ends_at') }}" required>
                            </label>
                        </div>

                        <label>
                            <span>Nhân viên trực <b data-agent-count>0/2</b></span>
                            <select name="agent_ids[]" multiple size="6" required data-agent-select>
                                @foreach($agents as $agent)
                                    <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="work-shifts-toggle">
                            <input type="checkbox" name="is_active" value="1" checked>
                            <span>Đang hoạt động</span>
                        </label>

                        <footer>
                            <small data-form-message>Chọn đúng 2 nhân viên cho mỗi ca trực.</small>
                            <button type="submit">Tạo ca trực</button>
                        </footer>
                    </form>
                </article>

                <section class="work-shifts-list" id="shift-list" aria-label="Danh sach ca truc">
                    <header class="work-shifts-section-head">
                        <div>
                            <p>Danh sách</p>
                            <h2>{{ $shifts->count() }} ca trực gần đây</h2>
                        </div>
                    </header>

                    @forelse($shifts as $shift)
                        <article class="work-shifts-card work-shifts-row">
                            <form class="work-shift-form" method="POST" action="{{ route('work-shifts.update', $shift) }}" data-shift-form>
                                @csrf
                                @method('PUT')

                                <div class="work-shifts-row-head">
                                    <div>
                                        <p>{{ $shift->is_active ? 'Đang hoạt động' : 'Tạm tắt' }}</p>
                                        <h3>{{ $shift->name ?: 'Ca trực #'.$shift->id }}</h3>
                                    </div>
                                    <span>{{ $shift->starts_at?->format('d/m H:i') }} - {{ $shift->ends_at?->format('d/m H:i') }}</span>
                                </div>

                                <label>
                                    <span>Tên ca</span>
                                    <input name="name" value="{{ old('name', $shift->name) }}" placeholder="Tên ca">
                                </label>

                                <div class="work-shifts-fields">
                                    <label>
                                        <span>Bắt đầu</span>
                                        <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $shift->starts_at?->format('Y-m-d\TH:i')) }}" required>
                                    </label>
                                    <label>
                                        <span>Kết thúc</span>
                                        <input type="datetime-local" name="ends_at" value="{{ old('ends_at', $shift->ends_at?->format('Y-m-d\TH:i')) }}" required>
                                    </label>
                                </div>

                                <label>
                                    <span>Nhân viên trực <b data-agent-count>{{ $shift->agents->count() }}/2</b></span>
                                    <select name="agent_ids[]" multiple size="6" required data-agent-select>
                                        @foreach($agents as $agent)
                                            <option value="{{ $agent->id }}" @selected($shift->agents->contains('id', $agent->id))>{{ $agent->name }}</option>
                                        @endforeach
                                    </select>
                                </label>

                                <label class="work-shifts-toggle">
                                    <input type="checkbox" name="is_active" value="1" @checked($shift->is_active)>
                                    <span>Đang hoạt động</span>
                                </label>

                                <footer>
                                    <small data-form-message>Chọn đúng 2 nhân viên cho mỗi ca trực.</small>
                                    <button type="submit">Lưu thay đổi</button>
                                </footer>
                            </form>

                            <form class="work-shifts-delete" method="POST" action="{{ route('work-shifts.destroy', $shift) }}" data-delete-shift>
                                @csrf
                                @method('DELETE')
                                <button type="submit">Xóa ca</button>
                            </form>
                        </article>
                    @empty
                        <article class="work-shifts-empty">
                            <h3>Chưa có ca trực</h3>
                            <p>Tạo ca trực đầu tiên để nhân viên có thể nhận hội thoại đúng ca.</p>
                        </article>
                    @endforelse
                </section>
            </section>
        </section>
    </main>
</div>
@endsection
