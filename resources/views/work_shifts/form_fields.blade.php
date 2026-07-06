<label>
    <span>Tên ca</span>
    <input name="name" placeholder="Ví dụ: Ca sáng 08:00 - 09:00" value="{{ old('name', $shift?->name) }}">
</label>

@php($systemDateLabel = now()->format('d/m/Y'))

<div class="shift-time-grid" aria-label="Thời gian ca trực">
    <label class="shift-time-field">
        <span>Bắt đầu ca</span>
        <input type="time" name="starts_time" value="{{ old('starts_time', $shift?->starts_at?->format('H:i')) }}" required>
        <small>Ngày hệ thống: {{ $systemDateLabel }}.</small>
    </label>
    <label class="shift-time-field">
        <span>Kết thúc ca</span>
        <input type="time" name="ends_time" value="{{ old('ends_time', $shift?->ends_at?->format('H:i')) }}" required>
        <small>Nếu qua đêm, hệ thống tự chuyển sang ngày tiếp theo.</small>
    </label>
</div>

<section class="shift-agent-picker" aria-label="Phân bổ nhân viên">
    <header>
        <div>
            <span>Phân bổ nhân viên</span>
            <small>Mỗi ca cần từ 1 đến 2 nhân viên CSKH.</small>
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
                <em>{{ (int) ($agent->active_conversations_count ?? 0) }} hội thoại</em>
            </label>
        @empty
            <p class="shift-agent-empty">Tất cả nhân viên đang nằm trong ca trực đang bật.</p>
        @endforelse
    </div>
</section>

<label class="work-shifts-toggle">
    <input type="checkbox" name="is_active" value="1" @checked($shift?->is_active ?? true)>
    <span>Bật ca trực này</span>
</label>
