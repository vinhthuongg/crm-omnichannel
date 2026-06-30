@extends('layouts.app', ['title' => 'Ca truc - CRM', 'bodyClass' => 'work-shifts-page'])

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/work-shifts.css') }}?v={{ filemtime(public_path('css/work-shifts.css')) }}">
@endpush

@push('scripts')
    <script src="{{ asset('js/work-shifts.js') }}?v={{ filemtime(public_path('js/work-shifts.js')) }}" defer></script>
@endpush

@section('content')
@php
    $overviewShifts = $shifts->take(4)->values();
    $staffShift = $currentShift ?: $nextShift;
    $staffMembers = $staffShift?->agents ?? $agents->take(4);
    $onlineCount = $staffMembers->where('is_active', true)->count();
    $busyCount = max(0, $staffMembers->count() - $onlineCount);
    $activeShiftCount = $shifts->where('is_active', true)->count();
    $maxLoad = max(10, (int) $staffMembers->max('active_conversations_count'));
@endphp

<div class="crm-shell" data-crm-shell>
    @include('partials.crm.chrome')

    <main class="crm-main">
        @include('partials.crm.topbar')

        <section class="work-shifts-shell" data-work-shifts-page>
            <header class="work-shifts-hero">
                <div>
                    <p class="work-shifts-eyebrow">Quan ly ca truc</p>
                    <h1>Ca truc va phan cong CSKH</h1>
                    <p>Quan ly lich truc, phan cong nhan vien va dam bao khach moi duoc dua vao dung nhom dang truc.</p>
                </div>
                <nav aria-label="Thao tac ca truc">
                    <a class="work-shifts-secondary-action" href="#shift-list">Danh sach ca</a>
                    <a class="work-shifts-primary-action" href="#create-shift">Tao ca truc</a>
                </nav>
            </header>

            @if(session('status'))
                <p class="work-shifts-alert is-success">{{ session('status') }}</p>
            @endif

            @if($errors->any())
                <p class="work-shifts-alert is-error">{{ $errors->first() }}</p>
            @endif

            <section class="work-shifts-summary" aria-label="Tong quan ca truc">
                <article>
                    <span>Tong ca truc</span>
                    <b>{{ $shifts->count() }}</b>
                    <small>{{ $activeShiftCount }} ca dang bat</small>
                </article>
                <article>
                    <span>Ca hien tai</span>
                    <b>{{ $currentShift?->name ?: 'Chua co' }}</b>
                    <small>{{ $currentShift ? $currentShift->starts_at?->format('H:i').' - '.$currentShift->ends_at?->format('H:i') : 'Ngoai khung truc' }}</small>
                </article>
                <article>
                    <span>Ca tiep theo</span>
                    <b>{{ $nextShift?->name ?: 'Chua co' }}</b>
                    <small>{{ $nextShift ? $nextShift->starts_at?->format('d/m H:i') : 'Chua len lich' }}</small>
                </article>
                <article>
                    <span>Nhan vien san sang</span>
                    <b>{{ $agents->count() }}</b>
                    <small>{{ $onlineCount }} dang trong ca hien thi</small>
                </article>
            </section>

            <nav class="work-shifts-tabs" aria-label="Dieu huong ca truc">
                <a class="active" href="#weekly-schedule">Lich truc</a>
                <a href="#staff-on-shift">Nhan su trong ca</a>
                <a href="#create-shift">Tao ca</a>
                <a href="#shift-list">Quan ly ca</a>
            </nav>

            <section class="shift-overview-grid" id="weekly-schedule">
                @forelse($overviewShifts as $shift)
                    @php
                        $isCurrent = $currentShift?->is($shift);
                        $isNext = ! $isCurrent && $nextShift?->is($shift);
                        $startsHour = (int) $shift->starts_at?->format('H');
                        $icon = $startsHour >= 20 || $startsHour < 6 ? 'nightlight' : ($startsHour >= 12 ? 'wb_twilight' : 'wb_sunny');
                    @endphp
                    <article class="shift-overview-card {{ $isCurrent ? 'is-current' : '' }}">
                        <header>
                            <span class="material-symbols-outlined" aria-hidden="true">{{ $icon }}</span>
                            <div>
                                <h2>{{ $shift->name ?: 'Ca truc #'.$shift->id }}</h2>
                                <p>{{ $isCurrent ? 'Dang dien ra' : ($isNext ? 'Sap toi' : ($shift->is_active ? 'Dang bat' : 'Tam tat')) }}</p>
                            </div>
                            <time>{{ $shift->starts_at?->format('H:i') }} - {{ $shift->ends_at?->format('H:i') }}</time>
                        </header>
                        <footer>
                            <span>{{ $shift->starts_at?->format('d/m/Y') }}</span>
                            <span>{{ $shift->agents->count() }} nhan vien</span>
                        </footer>
                    </article>
                @empty
                    <article class="shift-overview-card is-empty">
                        <header>
                            <span class="material-symbols-outlined" aria-hidden="true">event_busy</span>
                            <div>
                                <h2>Chua co lich truc</h2>
                                <p>Tao ca truc dau tien de bat dau phan bo hoi thoai.</p>
                            </div>
                        </header>
                    </article>
                @endforelse
            </section>

            <section class="shift-staff-panel" id="staff-on-shift">
                <header>
                    <div>
                        <p>Nhan su trong ca</p>
                        <h2>{{ $staffShift ? $staffShift->name : 'Chua co ca dang hien thi' }}</h2>
                    </div>
                    <div class="shift-staff-stats">
                        <span><i class="online"></i>{{ $onlineCount }} san sang</span>
                        <span><i class="break"></i>{{ $busyCount }} ban</span>
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
                                <p>{{ $agent->email }}</p>
                            </div>
                            <div class="shift-load">
                                <div>
                                    <span>Tai hoi thoai</span>
                                    <small class="{{ $isBusy ? 'danger' : '' }}">{{ $load }}/10</small>
                                </div>
                                <b><i style="width: {{ $percent }}%"></i></b>
                            </div>
                            <span class="shift-status-pill {{ $isBusy ? 'is-busy' : '' }}">{{ $isBusy ? 'Can giam tai' : 'San sang' }}</span>
                        </article>
                    @empty
                        <article class="work-shifts-empty">
                            <h3>Chua co nhan vien trong ca</h3>
                            <p>Moi ca can dung 2 nhan vien CSKH de nhan khach moi.</p>
                        </article>
                    @endforelse
                </div>
            </section>

            <section class="work-shifts-management">
                <article class="work-shifts-card work-shifts-create" id="create-shift">
                    <header>
                        <p>Ca moi</p>
                        <h2>Tao lich truc</h2>
                    </header>

                    <form class="work-shift-form" method="POST" action="{{ route('work-shifts.store') }}" data-shift-form>
                        @csrf
                        <label>
                            <span>Ten ca</span>
                            <input name="name" placeholder="Vi du: Ca sang 08:00 - 09:00" value="{{ old('name') }}">
                        </label>

                        <div class="work-shifts-fields">
                            <label>
                                <span>Bat dau</span>
                                <input type="datetime-local" name="starts_at" value="{{ old('starts_at') }}" required>
                            </label>
                            <label>
                                <span>Ket thuc</span>
                                <input type="datetime-local" name="ends_at" value="{{ old('ends_at') }}" required>
                            </label>
                        </div>

                        <label>
                            <span>Nhan vien truc <b data-agent-count>0/2</b></span>
                            <select name="agent_ids[]" multiple size="6" required data-agent-select>
                                @foreach($agents as $agent)
                                    <option value="{{ $agent->id }}">{{ $agent->name }} - {{ $agent->email }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="work-shifts-toggle">
                            <input type="checkbox" name="is_active" value="1" checked>
                            <span>Bat ca truc nay</span>
                        </label>

                        <footer>
                            <small data-form-message>Chon dung 2 nhan vien cho moi ca truc.</small>
                            <button type="submit">Tao ca truc</button>
                        </footer>
                    </form>
                </article>

                <section class="work-shifts-list" id="shift-list" aria-label="Danh sach ca truc">
                    <header class="work-shifts-section-head">
                        <div>
                            <p>Quan ly</p>
                            <h2>{{ $shifts->count() }} ca truc gan day</h2>
                        </div>
                    </header>

                    @forelse($shifts as $shift)
                        <article class="work-shifts-card work-shifts-row">
                            <form class="work-shift-form" method="POST" action="{{ route('work-shifts.update', $shift) }}" data-shift-form>
                                @csrf
                                @method('PUT')

                                <div class="work-shifts-row-head">
                                    <div>
                                        <p>{{ $shift->is_active ? 'Dang bat' : 'Tam tat' }}</p>
                                        <h3>{{ $shift->name ?: 'Ca truc #'.$shift->id }}</h3>
                                    </div>
                                    <span>{{ $shift->starts_at?->format('d/m H:i') }} - {{ $shift->ends_at?->format('d/m H:i') }}</span>
                                </div>

                                <label>
                                    <span>Ten ca</span>
                                    <input name="name" value="{{ old('name', $shift->name) }}" placeholder="Ten ca">
                                </label>

                                <div class="work-shifts-fields">
                                    <label>
                                        <span>Bat dau</span>
                                        <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $shift->starts_at?->format('Y-m-d\TH:i')) }}" required>
                                    </label>
                                    <label>
                                        <span>Ket thuc</span>
                                        <input type="datetime-local" name="ends_at" value="{{ old('ends_at', $shift->ends_at?->format('Y-m-d\TH:i')) }}" required>
                                    </label>
                                </div>

                                <label>
                                    <span>Nhan vien truc <b data-agent-count>{{ $shift->agents->count() }}/2</b></span>
                                    <select name="agent_ids[]" multiple size="6" required data-agent-select>
                                        @foreach($agents as $agent)
                                            <option value="{{ $agent->id }}" @selected($shift->agents->contains('id', $agent->id))>{{ $agent->name }} - {{ $agent->email }}</option>
                                        @endforeach
                                    </select>
                                </label>

                                <label class="work-shifts-toggle">
                                    <input type="checkbox" name="is_active" value="1" @checked($shift->is_active)>
                                    <span>Bat ca truc nay</span>
                                </label>

                                <footer>
                                    <small data-form-message>Chon dung 2 nhan vien cho moi ca truc.</small>
                                    <button type="submit">Luu thay doi</button>
                                </footer>
                            </form>

                            <form class="work-shifts-delete" method="POST" action="{{ route('work-shifts.destroy', $shift) }}" data-delete-shift>
                                @csrf
                                @method('DELETE')
                                <button type="submit">Xoa ca</button>
                            </form>
                        </article>
                    @empty
                        <article class="work-shifts-empty">
                            <h3>Chua co ca truc</h3>
                            <p>Tao ca truc dau tien de nhan vien co the nhan hoi thoai dung ca.</p>
                        </article>
                    @endforelse
                </section>
            </section>
        </section>
    </main>
</div>
@endsection
