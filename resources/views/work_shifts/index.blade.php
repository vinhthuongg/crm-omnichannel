@extends('layouts.app', ['title' => 'Ca truc - CRM', 'bodyClass' => 'messenger-page'])

@section('content')
<main class="facebook-pages-shell">
    <section class="facebook-pages-panel">
        <header>
            <div>
                <p>Work Shifts</p>
                <h1>Quan ly ca truc CSKH</h1>
            </div>
            <div class="facebook-page-actions">
                <a href="{{ route('crm.conversations') }}">Conversations</a>
            </div>
        </header>

        @if(session('status'))
            <p class="field-success">{{ session('status') }}</p>
        @endif
        @if($errors->any())
            <p class="field-error">{{ $errors->first() }}</p>
        @endif

        <form class="facebook-page-card" method="POST" action="{{ route('work-shifts.store') }}">
            @csrf
            <input name="name" placeholder="Ten ca" value="{{ old('name') }}">
            <input type="datetime-local" name="starts_at" value="{{ old('starts_at') }}" required>
            <input type="datetime-local" name="ends_at" value="{{ old('ends_at') }}" required>
            <select name="agent_ids[]" multiple size="4" required>
                @foreach($agents as $agent)
                    <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                @endforeach
            </select>
            <label><input type="checkbox" name="is_active" value="1" checked> Dang hoat dong</label>
            <button type="submit">Tao ca truc</button>
        </form>

        <div class="facebook-page-list">
            @foreach($shifts as $shift)
                <article class="facebook-page-card">
                    <form method="POST" action="{{ route('work-shifts.update', $shift) }}">
                        @csrf
                        @method('PUT')
                        <input name="name" value="{{ old('name', $shift->name) }}" placeholder="Ten ca">
                        <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $shift->starts_at?->format('Y-m-d\TH:i')) }}" required>
                        <input type="datetime-local" name="ends_at" value="{{ old('ends_at', $shift->ends_at?->format('Y-m-d\TH:i')) }}" required>
                        <select name="agent_ids[]" multiple size="4" required>
                            @foreach($agents as $agent)
                                <option value="{{ $agent->id }}" @selected($shift->agents->contains('id', $agent->id))>{{ $agent->name }}</option>
                            @endforeach
                        </select>
                        <label><input type="checkbox" name="is_active" value="1" @checked($shift->is_active)> Dang hoat dong</label>
                        <button type="submit">Luu thay doi</button>
                    </form>
                    <form method="POST" action="{{ route('work-shifts.destroy', $shift) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit">Xoa</button>
                    </form>
                </article>
            @endforeach
        </div>
    </section>
</main>
@endsection
