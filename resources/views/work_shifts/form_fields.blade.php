<label>
    <span>Ten ca</span>
    <input name="name" placeholder="Vi du: Ca sang 08:00 - 09:00" value="{{ old('name', $shift?->name) }}">
</label>

<div class="work-shifts-fields">
    <label>
        <span>Bat dau</span>
        <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $shift?->starts_at?->format('Y-m-d\TH:i')) }}" required>
    </label>
    <label>
        <span>Ket thuc</span>
        <input type="datetime-local" name="ends_at" value="{{ old('ends_at', $shift?->ends_at?->format('Y-m-d\TH:i')) }}" required>
    </label>
</div>

<label>
    <span>Nhan vien truc <b data-agent-count>{{ $shift ? $shift->agents->count() : 0 }}/2</b></span>
    <select name="agent_ids[]" multiple size="6" required data-agent-select>
        @foreach($agents as $agent)
            <option value="{{ $agent->id }}" @selected($shift?->agents->contains('id', $agent->id))>{{ $agent->name }} - {{ $agent->email }}</option>
        @endforeach
    </select>
</label>

<label class="work-shifts-toggle">
    <input type="checkbox" name="is_active" value="1" @checked($shift?->is_active ?? true)>
    <span>Bat ca truc nay</span>
</label>
