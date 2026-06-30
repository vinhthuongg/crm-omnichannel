@extends('layouts.app', ['title' => 'Khach hang - CRM'])

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/crm/customers.css') }}?v={{ filemtime(public_path('css/crm/customers.css')) }}">
@endpush

@section('content')
<div class="crm-shell customers-page" data-crm-shell>
    @php($activeSection = 'customers')
    @include('partials.crm.chrome')

    <main class="crm-main">
        @include('partials.crm.topbar')

        <section class="customers-hero">
            <div>
                <h1>Danh sach Khach hang</h1>
                <p>Quan ly va theo doi thong tin tiem nang tu cac kenh dang ket noi.</p>
            </div>
            <div class="customers-hero-metrics" aria-label="Tong quan khach hang">
                <span>{{ number_format($summary['total']) }} khach</span>
                <span>{{ number_format($summary['facebook']) }} Facebook</span>
                <span>{{ number_format($summary['zalo']) }} Zalo</span>
            </div>
        </section>

        <section class="customers-filter-card" aria-label="Bo loc khach hang">
            <form method="GET" action="{{ route('crm.customers') }}">
                <label>
                    <span>Nguon khach</span>
                    <select name="channel">
                        <option value="">Tat ca nguon</option>
                        <option value="facebook" @selected($filters['channel'] === 'facebook')>Facebook</option>
                        <option value="zalo" @selected($filters['channel'] === 'zalo')>Zalo</option>
                    </select>
                </label>
                <label>
                    <span>Nhom / tag</span>
                    <select name="tag_id">
                        <option value="0">Tat ca tag</option>
                        @foreach($customerTags as $tag)
                            <option value="{{ $tag->id }}" @selected((int) $filters['tag_id'] === $tag->id)>{{ $tag->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span>Nhan vien</span>
                    <select name="agent_id">
                        <option value="0">Tat ca nhan vien</option>
                        @foreach($agents as $agent)
                            <option value="{{ $agent->id }}" @selected((int) $filters['agent_id'] === $agent->id)>{{ $agent->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span>Trang thai</span>
                    <select name="status">
                        <option value="">Tat ca trang thai</option>
                        <option value="open" @selected($filters['status'] === 'open')>Dang xu ly</option>
                        <option value="pending" @selected($filters['status'] === 'pending')>Dang cho</option>
                        <option value="closed" @selected($filters['status'] === 'closed')>Da dong</option>
                    </select>
                </label>
                <label>
                    <span>Thoi gian</span>
                    <input type="date" name="date" value="{{ $filters['date'] }}">
                </label>
                <label class="customers-search-field">
                    <span>Tim kiem</span>
                    <input name="q" value="{{ $filters['q'] }}" placeholder="Ten, so dien thoai, email hoac ID kenh">
                </label>
                <div class="customers-filter-actions">
                    <button type="submit">Loc</button>
                    @if($filters['q'] !== '' || $filters['channel'] !== '' || $filters['status'] !== '' || (int) $filters['agent_id'] > 0 || (int) $filters['tag_id'] > 0 || $filters['date'] !== '')
                        <a href="{{ route('crm.customers') }}">Xoa loc</a>
                    @endif
                </div>
            </form>
        </section>

        <section class="customers-board">
            <div class="customers-table-wrap">
                <table class="customers-table">
                    <thead>
                        <tr>
                            <th>Khach hang</th>
                            <th>Lien he</th>
                            <th>Xe quan tam</th>
                            <th>Hoat dong cuoi</th>
                            <th>Nhan vien</th>
                            <th>Trang thai</th>
                            <th>Thao tac</th>
                        </tr>
                    </thead>
                    <tbody>
                        @if($customerRows->isEmpty())
                            <tr>
                                <td colspan="7">
                                    <div class="customers-empty">
                                        <h2>Chua co khach hang phu hop</h2>
                                        <p>Khach hang se xuat hien tai day sau khi nhan tin vao kenh dang ket noi.</p>
                                    </div>
                                </td>
                            </tr>
                        @else
                        @foreach($customerRows as $row)
                            <tr>
                                <td>
                                    <div class="customer-person">
                                        <span class="customer-avatar">
                                            @if($row['avatar'])
                                                <img src="{{ $row['avatar'] }}" alt="{{ $row['name'] }}">
                                            @else
                                                {{ $row['initial'] }}
                                            @endif
                                        </span>
                                        <span>
                                            <span class="customer-name">{{ $row['name'] }}</span>
                                            <small class="customer-source customer-source-{{ $row['channel'] }}">
                                                {{ $row['channel_label'] }}
                                            </small>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <span class="customer-contact">{{ $row['phone'] }}</span>
                                    <small>{{ $row['email'] }}</small>
                                </td>
                                <td>
                                    @if($row['tags']->isNotEmpty())
                                        <div class="customer-interest-tags">
                                            @foreach($row['tags'] as $tag)
                                                <span style="--tag-color: {{ $tag['color'] }}">{{ $tag['name'] }}</span>
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="customer-muted">Chua co du lieu</span>
                                    @endif
                                </td>
                                <td>
                                    <span>{{ $row['last_date'] }}</span>
                                    <small>{{ $row['last_time'] }}</small>
                                </td>
                                <td>
                                    @if($row['assignee'])
                                        <span class="customer-agent">
                                            <span class="customer-agent-avatar">{{ $row['assignee_initial'] }}</span>
                                            {{ $row['assignee'] }}
                                        </span>
                                    @else
                                        <span class="customer-muted">Chua gan</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="customer-status customer-status-{{ $row['status_class'] }}">{{ $row['status_label'] }}</span>
                                </td>
                                <td>
                                    @if($row['conversation_url'])
                                        <a class="customer-action-button" href="{{ $row['conversation_url'] }}" aria-label="Mo hoi thoai">
                                            <span class="material-symbols-outlined" aria-hidden="true">open_in_new</span>
                                        </a>
                                    @else
                                        <span class="customer-muted">-</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        @endif
                    </tbody>
                </table>
            </div>

            <div class="customers-board-footer">
                <span>
                    Hien thi {{ $customers->firstItem() ?? 0 }}-{{ $customers->lastItem() ?? 0 }} cua {{ number_format($customers->total()) }} khach hang
                </span>
                <div class="customers-pagination">
                    {{ $customers->links() }}
                </div>
            </div>
        </section>
    </main>
</div>

<script>
    document.querySelector('[data-sidebar-toggle]')?.addEventListener('click', function () {
        document.querySelector('[data-crm-shell]')?.classList.toggle('sidebar-collapsed');
    });
</script>
@endsection
