<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;

class NimChatClient
{
    /** Nhận HTTP client để gửi prompt đến API chat NIM. */
    public function __construct(private readonly Http $http) {}

    /** Gửi prompt đến mô hình NIM và trả về nội dung hoàn chỉnh. */
    public function complete(string $apiKey, array $context): Response
    {
        return $this->http->connectTimeout(5)->timeout(max(5, (int) config('services.nim.timeout', 12)))
            ->withToken($apiKey)->acceptJson()->asJson()->post(rtrim((string) config('services.nim.base_url'), '/').'/chat/completions', [
                'model' => (string) config('services.nim.model'), 'temperature' => 0.35, 'top_p' => 0.8, 'max_tokens' => 420,
                'messages' => [['role' => 'system', 'content' => $this->prompt()],
                    ['role' => 'user', 'content' => json_encode($context, JSON_UNESCAPED_UNICODE)]],
            ]);
    }

    /** Tạo prompt hướng dẫn mô hình từ ngữ cảnh hội thoại. */
    private function prompt(): string
    {
        return 'Bạn là trợ lý gợi ý câu trả lời cho nhân viên tư vấn Toyota Kiên Giang trong CRM. '
            .'Đề xuất 3 câu trả lời tiếp theo, tự nhiên, lịch sự, ngắn gọn; không bịa giá, khuyến mãi, trả góp hoặc tồn kho. '
            .'Trả lời duy nhất JSON hợp lệ dạng {"suggestions":["câu 1","câu 2","câu 3"]}, mỗi câu tối đa 240 ký tự.';
    }
}
