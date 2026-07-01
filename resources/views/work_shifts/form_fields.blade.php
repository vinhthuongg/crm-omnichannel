<label>
    <span>Ten ca</span>
    <input name="name" placeholder="Vi du: Ca sang 08:00 - 09:00" value="{{ old('name', $shift?->name) }}">
</label>

<div class="shift-time-grid" aria-label="Thoi gian ca truc">
    <label class="shift-time-field">
        <span>Bat dau ca</span>
        <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $shift?->starts_at?->format('Y-m-d\TH:i')) }}" required>
        <small>Ngay va gio nhan khach moi.</small>
    </label>
    <label class="shift-time-field">
        <span>Ket thuc ca</span>
        <input type="datetime-local" name="ends_at" value="{{ old('ends_at', $shift?->ends_at?->format('Y-m-d\TH:i')) }}" required>
        <small>Khach moi sau moc nay se vao ca tiep theo.</small>
    </label>
</div>

<section class="shift-agent-picker" aria-label="Phan bo nhan vien">
    <header>
        <div>
            <span>Phan bo nhan vien</span>
            <small>Moi ca can dung 2 nhan vien CSKH.</small>
        </div>
        <b data-agent-count>{{ $shift ? $shift->agents->count() : 0 }}/2</b>
    </header>
    <div class="shift-agent-options">
        @foreach($agents as $agent)
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
        @endforeach
    </div>
</section>

<label class="work-shifts-toggle">
    <input type="checkbox" name="is_active" value="1" @checked($shift?->is_active ?? true)>
    <span>Bat ca truc nay</span>
</label>
