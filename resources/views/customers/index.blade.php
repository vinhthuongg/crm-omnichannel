@extends('layouts.app', ['title' => 'Khách hàng - CRM'])

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
                <h1>Danh sách Khách hàng</h1>
                <p>Quản lý và theo dõi thông tin tiềm năng từ các kênh đang kết nối.</p>
            </div>
            <div class="customers-hero-metrics" aria-label="Tổng quan khách hàng">
                <span>{{ number_format($summary['total']) }} khách</span>
                <span>{{ number_format($summary['facebook']) }} Facebook</span>
                <span>{{ number_format($summary['zalo']) }} Zalo</span>
            </div>
        </section>

        <section class="customers-filter-card" aria-label="Bộ lọc khách hàng">
            <form method="GET" action="{{ route('crm.customers') }}" data-customers-filter-form>
                <label>
                    <span>Nguồn khách</span>
                    <select name="channel">
                        <option value="">Tất cả nguồn</option>
                        <option value="facebook" @selected($filters['channel'] === 'facebook')>Facebook</option>
                        <option value="zalo" @selected($filters['channel'] === 'zalo')>Zalo</option>
                    </select>
                </label>
                <label>
                    <span>Nhóm / Tag</span>
                    <select name="tag_id">
                        <option value="0">Tất cả tag</option>
                        @foreach($customerTags as $tag)
                            <option value="{{ $tag->id }}" @selected((int) $filters['tag_id'] === $tag->id)>{{ $tag->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span>Nhân viên</span>
                    <select name="agent_id">
                        <option value="0">Tất cả nhân viên</option>
                        @foreach($agents as $agent)
                            <option value="{{ $agent->id }}" @selected((int) $filters['agent_id'] === $agent->id)>{{ $agent->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span>Trạng thái</span>
                    <select name="status">
                        <option value="">Tất cả trạng thái</option>
                        <option value="in_progress" @selected($filters['status'] === 'in_progress')>Đang tư vấn</option>
                        <option value="waiting" @selected($filters['status'] === 'waiting')>Khách đợi</option>
                        <option value="closed" @selected($filters['status'] === 'closed')>Đã đóng</option>
                    </select>
                </label>
                <label>
                    <span>Thời gian</span>
                    <input type="date" name="date" value="{{ $filters['date'] }}">
                </label>
                <label class="customers-search-field">
                    <span>Tìm kiếm</span>
                    <input name="q" value="{{ $filters['q'] }}" placeholder="Tìm kiếm vector theo tên, số điện thoại, nhu cầu hoặc nội dung chat" data-customers-search-input>
                </label>
                <div class="customers-filter-actions">
                    @if($filters['q'] !== '' || $filters['channel'] !== '' || $filters['status'] !== '' || (int) $filters['agent_id'] > 0 || (int) $filters['tag_id'] > 0 || $filters['date'] !== '')
                        <a href="{{ route('crm.customers') }}">Xóa lọc</a>
                    @endif
                </div>
            </form>
        </section>

        <section class="customers-board">
            <div class="customers-table-wrap">
                <table class="customers-table">
                    <thead>
                        <tr>
                            <th>Khách hàng</th>
                            <th>Liên hệ</th>
                            <th>Mục quan tâm</th>
                            <th>Hoạt động cuối</th>
                            <th>Nhân viên</th>
                            <th>Trạng thái</th>
                            <th>Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        @if($customerRows->isEmpty())
                            <tr>
                                <td colspan="7">
                                    <div class="customers-empty">
                                        <h2>Chưa có khách hàng phù hợp</h2>
                                        <p>Khách hàng sẽ xuất hiện tại đây sau khi gửi tin nhắn đến kênh đang được kết nối.</p>
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
                                        <span class="customer-muted">Chưa có dữ liệu</span>
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
                                        <span class="customer-muted">Chưa gán</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="customer-status customer-status-{{ $row['status_class'] }}">{{ $row['status_label'] }}</span>
                                </td>
                                <td>
                                    @if($row['conversation_url'])
                                        <a class="customer-action-button" href="{{ $row['conversation_url'] }}" aria-label="Mở hội thoại">
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
                    Hiển thị {{ $customers->firstItem() ?? 0 }}-{{ $customers->lastItem() ?? 0 }} của {{ number_format($customers->total()) }} khách hàng
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

    const customersPage = document.querySelector('.customers-page');
    const customersFilterForm = document.querySelector('[data-customers-filter-form]');
    const customersSearchInput = document.querySelector('[data-customers-search-input]');
    let customersSearchTimer;
    let customersFetchController;

    function customersUrlFromForm() {
        const data = new FormData(customersFilterForm);
        const params = new URLSearchParams();

        data.forEach(function (value, key) {
            if (String(value).trim() !== '' && String(value) !== '0') {
                params.set(key, value);
            }
        });

        const query = params.toString();

        return customersFilterForm.action + (query ? `?${query}` : '');
    }

    function syncCustomersFormFromUrl(url) {
        const params = new URL(url, window.location.origin).searchParams;

        customersFilterForm?.querySelectorAll('[name]').forEach(function (field) {
            if (field.type === 'date' || field.tagName === 'INPUT' || field.tagName === 'SELECT') {
                field.value = params.get(field.name) || (field.name === 'tag_id' || field.name === 'agent_id' ? '0' : '');
            }
        });
    }

    async function loadCustomers(url, pushState = true) {
        if (!customersPage) {
            window.location.href = url;
            return;
        }

        customersFetchController?.abort();
        customersFetchController = new AbortController();
        customersPage.classList.add('is-loading');

        try {
            const response = await fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html',
                },
                signal: customersFetchController.signal,
            });

            if (!response.ok) {
                throw new Error('Không tải được dữ liệu khách hàng.');
            }

            const html = await response.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const nextBoard = doc.querySelector('.customers-board');
            const nextMetrics = doc.querySelector('.customers-hero-metrics');
            const nextActions = doc.querySelector('.customers-filter-actions');

            if (nextBoard && document.querySelector('.customers-board')) {
                document.querySelector('.customers-board').replaceWith(nextBoard);
            }

            if (nextMetrics && document.querySelector('.customers-hero-metrics')) {
                document.querySelector('.customers-hero-metrics').replaceWith(nextMetrics);
            }

            if (nextActions && document.querySelector('.customers-filter-actions')) {
                document.querySelector('.customers-filter-actions').replaceWith(nextActions);
            }

            if (pushState) {
                window.history.pushState({customersUrl: url}, '', url);
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                window.location.href = url;
            }
        } finally {
            customersPage.classList.remove('is-loading');
        }
    }

    function scheduleCustomersLoad(delay = 0) {
        clearTimeout(customersSearchTimer);
        customersSearchTimer = setTimeout(function () {
            loadCustomers(customersUrlFromForm());
        }, delay);
    }

    customersFilterForm?.querySelectorAll('select, input[type="date"]').forEach(function (field) {
        field.addEventListener('change', function () {
            scheduleCustomersLoad();
        });
    });

    customersSearchInput?.addEventListener('input', function () {
        scheduleCustomersLoad(450);
    });

    customersFilterForm?.addEventListener('submit', function (event) {
        event.preventDefault();
        loadCustomers(customersUrlFromForm());
    });

    document.addEventListener('click', function (event) {
        const paginationLink = event.target.closest('.customers-pagination a');
        const clearLink = event.target.closest('.customers-filter-actions a');
        const link = paginationLink || clearLink;

        if (!link) {
            return;
        }

        event.preventDefault();
        syncCustomersFormFromUrl(link.href);
        loadCustomers(link.href);
    });

    window.addEventListener('popstate', function () {
        syncCustomersFormFromUrl(window.location.href);
        loadCustomers(window.location.href, false);
    });
</script>
@endsection
