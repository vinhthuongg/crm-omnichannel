<label>
    <span>Ten ca</span>
    <input name="name" placeholder="Vi du: Ca sang 08:00 - 09:00" value="{{ old('name', $shift?->name) }}">
</label>

@php($systemDateLabel = now()->format('d/m/Y'))

<div class="shift-time-grid" aria-label="Thoi gian ca truc">
    <label class="shift-time-field">
        <span>Bat dau ca</span>
        <input type="time" name="starts_time" value="{{ old('starts_time', $shift?->starts_at?->format('H:i')) }}" required>
        <small>Ngay he thong: {{ $systemDateLabel }}.</small>
    </label>
    <label class="shift-time-field">
        <span>Ket thuc ca</span>
        <input type="time" name="ends_time" value="{{ old('ends_time', $shift?->ends_at?->format('H:i')) }}" required>
        <small>Neu qua dem, he thong tu chuyen sang ngay tiep theo.</small>
    </label>
</div>

<section class="shift-agent-picker" aria-label="Phan bo nhan vien">
    <header>
        <div>
            <span>Phan bo nhan vien</span>
            <small>Moi ca can tu 1 den 2 nhan vien CSKH.</small>
        </div>
        <b data-agent-count>{{ $shift ? $shift->agents->count() : 0 }}/2</b>
    </header>
    <div class="shift-agent-options">
        @forelse($agents as $agent)
            @php($isSelected = (bool) $shift?->agents->contains('id', $agent->id))
            <label class="shift-agent-option">
                <input
                    type="checkbox"
                    name="agent_ids[]"
                    value="{{ $agent->id }}"
                    data-agent-checkbox
                    @checked($isSelected)
                >
                <span class="shift-agent-avatar">{{ strtoupper(substr($agent->name, 0, 1)) }}</span>
                <span>
                    <strong>{{ $agent->name }}</strong>
                    <small>{{ $agent->email }}</small>
                </span>
                <em>{{ (int) ($agent->active_conversations_count ?? 0) }} hoi thoai</em>
            </label>
        @empty
            <p class="shift-agent-empty">Tat ca nhan vien dang nam trong ca truc dang bat.</p>
        @endforelse
    </div>
</section>

<label class="work-shifts-toggle">
    <input type="checkbox" name="is_active" value="1" @checked($shift?->is_active ?? true)>
    <span>Bat ca truc nay</span>
</label>
