@extends('layouts.app', ['title' => 'Ca truc - CRM', 'bodyClass' => 'work-shifts-page'])

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/work-shifts.css') }}?v={{ filemtime(public_path('css/work-shifts.css')) }}">
@endpush

@push('scripts')
    <script src="{{ asset('js/work-shifts.js') }}?v={{ filemtime(public_path('js/work-shifts.js')) }}" defer></script>
@endpush

@section('content')
<div class="crm-shell" data-crm-shell>
    @include('partials.crm.chrome')

    <main class="crm-main">
        @include('partials.crm.topbar')

<section class="work-shifts-shell" data-work-shifts-page>
    <header class="work-shifts-topbar">
        <div>
            <p>Work Shifts</p>
            <h1>Quan ly ca truc CSKH</h1>
        </div>
        <nav aria-label="Dieu huong nhanh">
            <a href="{{ route('crm.conversations') }}">
                Conversations
            </a>
            <a href="{{ route('dashboard') }}">
                Dashboard
            </a>
        </nav>
    </header>

    @if(session('status'))
        <p class="work-shifts-alert is-success">{{ session('status') }}</p>
    @endif

    @if($errors->any())
        <p class="work-shifts-alert is-error">{{ $errors->first() }}</p>
    @endif

    <section class="work-shifts-grid">
        <article class="work-shifts-card work-shifts-create">
            <header>
                <p>Ca moi</p>
                <h2>Tao ca truc</h2>
            </header>

            <form class="work-shift-form" method="POST" action="{{ route('work-shifts.store') }}" data-shift-form>
                @csrf
                <label>
                    <span>Ten ca</span>
                    <input name="name" placeholder="Vi du: Ca sang 08:00 - 12:00" value="{{ old('name') }}">
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
                            <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="work-shifts-toggle">
                    <input type="checkbox" name="is_active" value="1" checked>
                    <span>Dang hoat dong</span>
                </label>

                <footer>
                    <small data-form-message>Chon dung 2 nhan vien cho moi ca truc.</small>
                    <button type="submit">
                        Tao ca truc
                    </button>
                </footer>
            </form>
        </article>

        <section class="work-shifts-list" aria-label="Danh sach ca truc">
            <header class="work-shifts-section-head">
                <div>
                    <p>Danh sach</p>
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
                                <p>{{ $shift->is_active ? 'Dang hoat dong' : 'Tam tat' }}</p>
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
                                    <option value="{{ $agent->id }}" @selected($shift->agents->contains('id', $agent->id))>{{ $agent->name }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="work-shifts-toggle">
                            <input type="checkbox" name="is_active" value="1" @checked($shift->is_active)>
                            <span>Dang hoat dong</span>
                        </label>

                        <footer>
                            <small data-form-message>Chon dung 2 nhan vien cho moi ca truc.</small>
                            <button type="submit">
                                Luu thay doi
                            </button>
                        </footer>
                    </form>

                    <form class="work-shifts-delete" method="POST" action="{{ route('work-shifts.destroy', $shift) }}" data-delete-shift>
                        @csrf
                        @method('DELETE')
                        <button type="submit">
                            Xoa ca
                        </button>
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
