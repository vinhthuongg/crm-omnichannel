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
                    <h1>Quan ly ca truc va Phan cong</h1>
                    <p>Theo doi lich truc va cau hinh quy tac phan bo hoi thoai tu dong cho nhom CSKH.</p>
                </div>
                <nav aria-label="Thao tac ca truc">
                    <a class="work-shifts-secondary-action" href="#assignment-rules">
                        <span class="material-symbols-outlined" aria-hidden="true">manufacturing</span>
                        Thiet lap phan cong
                    </a>
                    <button class="work-shifts-primary-action" type="button" data-open-shift-dialog="create-shift-dialog">
                        <span class="material-symbols-outlined" aria-hidden="true">add</span>
                        Tao lich truc moi
                    </button>
                </nav>
            </header>

            @if(session('status'))
                <p class="work-shifts-alert is-success">{{ session('status') }}</p>
            @endif

            @if($errors->any())
                <p class="work-shifts-alert is-error">{{ $errors->first() }}</p>
            @endif

            <nav class="work-shifts-tabs" aria-label="Dieu huong ca truc">
                <a class="active" href="#weekly-schedule">Lich truc tuan nay</a>
                <a href="#staff-on-shift">Danh sach nhan vien</a>
                <a href="#assignment-rules">Quy tac phan cong</a>
                <a href="#shift-list">Lich su ca truc</a>
            </nav>

            <section class="shift-overview-grid" id="weekly-schedule">
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
                                <h2>{{ $shift->name ?: 'Ca truc #'.$shift->id }}</h2>
                                <p>{{ $isCurrent ? 'Dang truc:' : ($isNext ? 'Sap toi:' : ($shift->is_active ? 'Dang truc:' : 'Tam tat:')) }}</p>
                            </div>
                            <time>{{ $shift->starts_at?->format('H:i') }} - {{ $shift->ends_at?->format('H:i') }}</time>
                        </header>
                        <footer>
                            <span>{{ $isCurrent ? 'Dang dien ra' : ($isNext ? 'Sap toi' : ($shift->is_active ? 'Dang bat' : 'Tam tat')) }}</span>
                            <span>{{ $shift->agents_count ?? $shift->agents->count() }} nhan vien</span>
                        </footer>
                    </a>
                @empty
                    <article class="shift-overview-card is-empty">
                        <header><span class="material-symbols-outlined" aria-hidden="true">event_busy</span><div><h2>Chua co lich truc</h2><p>Tao ca truc dau tien de bat dau phan bo hoi thoai.</p></div></header>
                    </article>
                @endforelse
            </section>

            <section class="shift-staff-panel" id="staff-on-shift">
                <header>
                    <div><h2>Nhan su ca hien tai {{ $staffShift ? '('.$staffShift->name.')' : '' }}</h2></div>
                    <div class="shift-staff-stats">
                        <span><i class="online"></i>Online ({{ $onlineCount }})</span>
                        <span><i class="break"></i>Ban ({{ $busyCount }})</span>
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
                            <span class="shift-agent-avatar">{{ strtoupper(substr($agent->name, 0, 1)) }}<i class="{{ $isBusy ? 'break' : 'online' }}"></i></span>
                            <div class="shift-agent-info"><h3>{{ $agent->name }}</h3><p>{{ $agent->email }}</p></div>
                            <div class="shift-load"><div><span>Tai hoi thoai</span><small class="{{ $isBusy ? 'danger' : '' }}">{{ $load }}/10</small></div><b><i style="width: {{ $percent }}%"></i></b></div>
                            <button class="shift-pause-button" type="button" disabled>{{ $isBusy ? 'Can giam tai' : 'Tam dung nhan' }}</button>
                        </article>
                    @empty
                        <article class="work-shifts-empty"><h3>Chua co nhan vien trong ca</h3><p>Moi ca can toi thieu 1 nhan vien CSKH de nhan khach moi.</p></article>
                    @endforelse
                </div>
                <a class="shift-view-all" href="#shift-list">Xem toan bo danh sach ({{ $agents->count() }} nhan vien)</a>
            </section>

            <section class="assignment-rules-card" id="assignment-rules">
                <header>
                    <div>
                        <h2>Quy tac phan cong nhanh</h2>
                        <p>Thiet lap cach he thong tu dong phan phoi tin nhan moi.</p>
                    </div>
                </header>
                <div class="assignment-rules-body">
                    <div class="assignment-rule-toggle">
                        <div>
                            <span>Phan cong tu dong</span>
                            <small>Tu dong giao chat cho nhan vien online.</small>
                        </div>
                        <label class="switch-toggle" aria-label="Bat phan cong tu dong">
                            <input type="checkbox" checked>
                            <span></span>
                        </label>
                    </div>
                    <div class="assignment-rule-limit">
                        <span>Gioi han mac dinh</span>
                        <small>So luong hoi thoai toi da moi nhan vien xu ly cung luc.</small>
                        <div class="assignment-slider">
                            <i style="width: 46%"></i>
                            <output>10</output>
                        </div>
                    </div>
                    <div class="assignment-mode-list">
                        <label class="assignment-mode active">
                            <input type="radio" name="assignment_mode" checked>
                            <span>Dua tren tai cong viec</span>
                            <small>Uu tien nguoi co it hoi thoai nhat.</small>
                        </label>
                        <label class="assignment-mode">
                            <input type="radio" name="assignment_mode">
                            <span>Xoay vong (Round Robin)</span>
                            <small>Chia deu theo thu tu lan luot.</small>
                        </label>
                    </div>
                    <p class="assignment-note">
                        Luu y: Gioi han nay co the duoc ghi de o cap do ca nhan trong phan Danh sach nhan vien.
                    </p>
                </div>
            </section>

            <section class="work-shifts-list" id="shift-list" aria-label="Danh sach ca truc">
                <header class="work-shifts-section-head">
                    <div><p>Quan ly</p><h2>{{ $managedShifts->count() }} ca truc</h2></div>
                    <div class="work-shifts-head-actions">
                        <form class="shift-inline-form" method="GET" action="{{ route('work-shifts.index') }}">
                            @if($staffShift)
                                <input type="hidden" name="shift_id" value="{{ $staffShift->id }}">
                            @endif
                            <label>
                                <span>Trang thai</span>
                                <select name="status" onchange="this.form.submit()">
                                    <option value="all" @selected($manageStatus === 'all')>Tat ca</option>
                                    <option value="active" @selected($manageStatus === 'active')>Dang bat</option>
                                    <option value="inactive" @selected($manageStatus === 'inactive')>Tam tat</option>
                                    <option value="current" @selected($manageStatus === 'current')>Dang dien ra</option>
                                </select>
                            </label>
                        </form>
                        <button class="work-shifts-primary-action" type="button" data-open-shift-dialog="create-shift-dialog">Tao ca truc</button>
                    </div>
                </header>

                <div class="work-shifts-table">
                    @forelse($managedShifts as $shift)
                        <article class="work-shifts-card work-shifts-row">
                            <div class="work-shifts-row-main">
                                <span class="shift-agent-avatar">{{ strtoupper(substr($shift->name ?: 'C', 0, 1)) }}</span>
                                <div><h3>{{ $shift->name ?: 'Ca truc #'.$shift->id }}</h3><p>{{ $shift->starts_at?->format('d/m/Y H:i') }} - {{ $shift->ends_at?->format('d/m/Y H:i') }}</p></div>
                            </div>
                            <div class="work-shifts-row-agents">
                                <span>{{ $shift->agents_count ?? $shift->agents->count() }}/2 nhan vien</span>
                                <small>{{ (int) $shift->waiting_conversations_count }} cho - {{ (int) $shift->handling_conversations_count }} dang xu ly - {{ (int) $shift->finished_conversations_count }} xong</small>
                            </div>
                            <span class="shift-status-pill {{ $shift->is_active ? '' : 'is-busy' }}">{{ $shift->is_active ? 'Dang bat' : 'Tam tat' }}</span>
                            <div class="work-shifts-row-actions">
                                <button type="button" data-open-shift-dialog="edit-shift-dialog-{{ $shift->id }}">Sua</button>
                                <form class="work-shifts-delete" method="POST" action="{{ route('work-shifts.destroy', $shift) }}" data-delete-shift>@csrf @method('DELETE')<button type="submit">Xoa</button></form>
                            </div>
                        </article>
                    @empty
                        <article class="work-shifts-empty"><h3>Chua co ca truc</h3><p>Tao ca truc dau tien de nhan vien co the nhan hoi thoai dung ca.</p></article>
                    @endforelse
                </div>
            </section>

            <dialog class="work-shift-dialog" id="create-shift-dialog" data-shift-dialog>
                <form class="work-shift-dialog-card work-shift-form" method="POST" action="{{ route('work-shifts.store') }}" data-shift-form>
                    @csrf
                    <header><div><p>Ca moi</p><h2>Tao lich truc</h2></div><button type="button" class="work-shift-dialog-close" data-close-shift-dialog aria-label="Dong">x</button></header>
                    @include('work_shifts.form_fields', ['shift' => null, 'agents' => $createAgents])
                    <footer><small data-form-message>Chon 1 den 2 nhan vien cho moi ca truc.</small><button type="submit">Tao ca truc</button></footer>
                </form>
            </dialog>

            @foreach($shifts as $shift)
                <dialog class="work-shift-dialog" id="edit-shift-dialog-{{ $shift->id }}" data-shift-dialog>
                    <form class="work-shift-dialog-card work-shift-form" method="POST" action="{{ route('work-shifts.update', $shift) }}" data-shift-form>
                        @csrf
                        @method('PUT')
                        <header><div><p>Chinh sua</p><h2>{{ $shift->name ?: 'Ca truc #'.$shift->id }}</h2></div><button type="button" class="work-shift-dialog-close" data-close-shift-dialog aria-label="Dong">x</button></header>
                        @include('work_shifts.form_fields', [
                            'shift' => $shift,
                            'agents' => $agents
                                ->filter(fn ($agent) => ! $busyAgentIds->contains($agent->id) || $shift->agents->contains('id', $agent->id))
                                ->values(),
                        ])
                        <footer><small data-form-message>Chon 1 den 2 nhan vien cho moi ca truc.</small><button type="submit">Luu thay doi</button></footer>
                    </form>
                </dialog>
            @endforeach
        </section>
    </main>
</div>
@endsection
