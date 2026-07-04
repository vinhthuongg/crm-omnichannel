<section class="dashboard-agent-performance">
    <header class="dashboard-section-header">
        <div>
            <h2>Hiệu suất nhân viên</h2>
            <p>Tổng hợp theo số hội thoại, tốc độ phản hồi và số điện thoại đã thu thập.</p>
        </div>
    </header>

    <section class="agent-kpi-grid">
        @foreach($agentDashboard['cards'] as $card)
            <article class="agent-kpi-card">
                <div>
                    <p>{{ $card['label'] }}</p>
                    <h2>{{ $card['value'] }}@if($card['suffix'] !== '') <small>{{ $card['suffix'] }}</small>@endif</h2>
                    <span class="{{ $card['tone'] }}">{{ $card['change'] }}</span>
                </div>
                <b>
                    <span class="material-symbols-outlined" aria-hidden="true">{{ $card['icon'] }}</span>
                </b>
            </article>
        @endforeach
    </section>

    <section class="agent-chart-grid">
        <article class="agent-panel">
            <header>
                <h3>Hội thoại xử lý theo nhân viên</h3>
                <button type="button" aria-label="Tùy chọn">
                    <span class="material-symbols-outlined" aria-hidden="true">more_vert</span>
                </button>
            </header>
            <div id="agent-handled-bar" class="agent-chart" aria-label="Hội thoại xử lý theo nhân viên"></div>
        </article>

        <article class="agent-panel">
            <header>
                <h3>Tốc độ phản hồi trung bình</h3>
                <span class="agent-chart-legend"><i></i>Toàn đội</span>
            </header>
            <div id="agent-response-line" class="agent-chart" aria-label="Tốc độ phản hồi trung bình"></div>
        </article>
    </section>

    <section class="agent-table-panel">
        <header>
            <h3>Chi tiết hiệu suất nhân viên</h3>
            <label>
                <span class="material-symbols-outlined" aria-hidden="true">search</span>
                <input type="search" placeholder="Tìm nhân viên...">
            </label>
        </header>
        <div class="agent-table-wrap">
            <table class="agent-performance-table">
                <thead>
                    <tr>
                        <th>Nhân viên</th>
                        <th>Tổng hội thoại</th>
                        <th>Đã xử lý</th>
                        <th>Đang xử lý</th>
                        <th>TG phản hồi TB</th>
                        <th>SĐT thu thập</th>
                        <th>Đánh giá</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($agentDashboard['rows'] as $agent)
                        <tr>
                            <td>
                                <span class="agent-avatar">
                                    @if($agent['avatar'])
                                        <img src="{{ $agent['avatar'] }}" alt="{{ $agent['name'] }}">
                                    @else
                                        {{ $agent['initial'] }}
                                    @endif
                                </span>
                                {{ $agent['name'] }}
                            </td>
                            <td>{{ number_format($agent['total_conversations']) }}</td>
                            <td><a href="{{ route('crm.conversations', ['status' => 'mine']) }}">{{ number_format($agent['processed_conversations']) }}</a></td>
                            <td>{{ number_format($agent['active_conversations']) }}</td>
                            <td>{{ number_format($agent['avg_response_minutes'], 1) }} phút</td>
                            <td>{{ number_format($agent['phone_collected']) }}</td>
                            <td><span class="agent-rating">{{ $agent['rating'] }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <footer>
            <span>Hiển thị {{ count($agentDashboard['rows']) }} nhân viên</span>
        </footer>
    </section>
</section>
