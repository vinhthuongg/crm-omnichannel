<?php

namespace Modules\Dashboard\Services;

use App\Models\User;
use Illuminate\Support\Carbon;

class AgentPerformanceService
{
    /** Nhận AgentPerformanceQueryService để truy vấn dữ liệu; AgentPerformanceFormatter để định dạng dữ liệu đầu ra. */
    public function __construct(private readonly AgentPerformanceQueryService $queries, private readonly AgentPerformanceFormatter $formatter) {}

    /** Lấy danh sách nhân viên mà người yêu cầu được phép xem báo cáo hiệu suất. */
    public function agentsFor(User $requester) { return $this->queries->agentsFor($requester); }

    /** Truy vấn và định dạng chỉ số hiệu suất chi tiết của một nhân viên trong khoảng ngày. */
    public function forAgent(User $agent, Carbon $start, Carbon $end, ?int $shiftId): array
    {
        $m = $this->queries->metrics($agent, $start, $end, $shiftId);
        return ['agent' => $this->formatter->agent($agent),
            'conversations' => ['total' => $m['total'], 'active' => $m['active'], 'waiting' => $m['waiting'], 'finished' => $m['finished'], 'sent_messages' => $m['sent']],
            'response' => ['responded_conversations' => $m['responded'], 'average_seconds' => $m['average_seconds'],
                'average_minutes' => $m['average_seconds'] === null ? null : round($m['average_seconds'] / 60, 2),
                'label' => $this->formatter->duration($m['average_seconds']), 'rate' => $this->formatter->percentage($m['responded'], $m['total'])],
            'phone' => ['conversations_with_phone' => $m['phones'], 'rate' => $this->formatter->percentage($m['phones'], $m['total'])],
            'process' => ['tagged_conversations' => $m['tagged'], 'classified_conversations' => $m['tagged'], 'noted_conversations' => $m['noted'],
                'tagged_rate' => $this->formatter->percentage($m['tagged'], $m['total']), 'classified_rate' => $this->formatter->percentage($m['tagged'], $m['total']),
                'noted_rate' => $this->formatter->percentage($m['noted'], $m['total']),
                'overall_rate' => $this->formatter->percentage($m['tagged'] * 2 + $m['noted'], $m['total'] * 3)]];
    }

    /** Cộng gộp hiệu suất của nhiều nhân viên thành các tỷ lệ phản hồi, thu thập số và gắn nhãn. */
    public function summary($agents, Carbon $start, Carbon $end, ?int $shiftId): array
    {
        $rows = $agents->map(fn (User $agent): array => $this->forAgent($agent, $start, $end, $shiftId));
        $total = (int) $rows->sum('conversations.total'); $responded = (int) $rows->sum('response.responded_conversations');
        $phones = (int) $rows->sum('phone.conversations_with_phone'); $tagged = (int) $rows->sum('process.tagged_conversations');
        $noted = (int) $rows->sum('process.noted_conversations');
        $weighted = (int) $rows->sum(fn (array $row): int => (int) ($row['response']['average_seconds'] ?? 0) * (int) $row['response']['responded_conversations']);
        $average = $responded > 0 ? (int) round($weighted / $responded) : null;
        return ['total_agents' => $agents->count(), 'total_conversations' => $total, 'responded_conversations' => $responded,
            'average_response_seconds' => $average, 'average_response_label' => $this->formatter->duration($average),
            'phone_collected' => ['conversations' => $phones, 'rate' => $this->formatter->percentage($phones, $total)],
            'process_compliance' => ['tagged_rate' => $this->formatter->percentage($tagged, $total),
                'classified_rate' => $this->formatter->percentage($tagged, $total), 'noted_rate' => $this->formatter->percentage($noted, $total),
                'overall_rate' => $this->formatter->percentage($tagged * 2 + $noted, $total * 3)]];
    }
}
