<?php

namespace Modules\Facebook\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FacebookMessagePayloadNormalizer
{
    public const TEXT_LIMIT = 1024;
    private const TITLE_LIMIT = 20;
    private const COUNT_LIMIT = 13;
    private const PAYLOAD_LIMIT = 1000;

    /** Chuẩn hóa message payload thành cấu trúc Messenger API chấp nhận. */
    public function normalize(array $message): array
    {
        $replies = $this->quickReplies((array) ($message['quick_replies'] ?? []));
        if ($replies === []) {
            unset($message['quick_replies']);
            return $message;
        }
        $message['quick_replies'] = $replies;
        if (mb_strlen((string) ($message['text'] ?? '')) > self::TEXT_LIMIT) {
            Log::info('Facebook quick reply text will be split before sending', [
                'original_length' => mb_strlen((string) $message['text']), 'limit' => self::TEXT_LIMIT,
            ]);
        }
        return $message;
    }

    /** Chia nội dung dài thành các đoạn không vượt giới hạn Messenger. */
    public function split(string $text): array
    {
        $chunks = [];
        while (mb_strlen($text) > self::TEXT_LIMIT) {
            $candidate = mb_substr($text, 0, self::TEXT_LIMIT);
            $breakAt = max(mb_strrpos($candidate, "\n\n") ?: 0, mb_strrpos($candidate, "\n") ?: 0, mb_strrpos($candidate, ' ') ?: 0);
            $breakAt = $breakAt > 0 ? $breakAt : self::TEXT_LIMIT;
            $chunks[] = trim(mb_substr($text, 0, $breakAt));
            $text = ltrim(mb_substr($text, $breakAt));
        }
        if ($text !== '' || $chunks === []) $chunks[] = trim($text);
        return array_values(array_filter($chunks, static fn (string $chunk): bool => $chunk !== ''));
    }

    /** Chuẩn hóa danh sách lựa chọn thành quick reply payload. */
    private function quickReplies(array $items): array
    {
        return collect($items)->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item): ?array {
                $type = (string) ($item['content_type'] ?? 'text');
                if ($type === 'user_phone_number') return ['content_type' => 'user_phone_number'];
                if ($type !== 'text') return null;
                $title = trim((string) ($item['title'] ?? ''));
                if ($title === '') return null;
                $payload = $this->alignedPayload($title, trim((string) ($item['payload'] ?? $title)));
                return ['content_type' => 'text', 'title' => mb_substr($title, 0, self::TITLE_LIMIT),
                    'payload' => mb_substr($payload, 0, self::PAYLOAD_LIMIT)];
            })->filter()->unique(fn (array $item): string => ($item['content_type'] ?? '').'|'.($item['title'] ?? ''))
            ->take(self::COUNT_LIMIT)->values()->all();
    }

    /** Đồng bộ payload với tiêu đề khi payload đầu vào thiếu hoặc không phù hợp. */
    private function alignedPayload(string $title, string $payload): string
    {
        $titleIntent = $this->intent($title);
        $payloadIntent = $this->intent($payload);
        if ($titleIntent !== null && ($payloadIntent === null || $payloadIntent !== $titleIntent)) {
            $fixed = $this->intentPayload($titleIntent);
            Log::info('Facebook quick reply payload aligned with title', [
                'title' => mb_substr($title, 0, self::TITLE_LIMIT), 'old_payload' => mb_substr($payload, 0, 160), 'new_payload' => $fixed,
            ]);
            return $fixed;
        }
        return $payload !== '' ? $payload : $title;
    }

    /** Suy luận intent đơn giản từ từ khóa xuất hiện trong nội dung. */
    private function intent(string $text): ?string
    {
        $text = Str::of($text)->lower()->ascii()->replaceMatches('/[^a-z0-9\s]+/', ' ')->replaceMatches('/\s+/', ' ')->trim()->toString();
        foreach ($this->keywords() as $intent => $keywords) foreach ($keywords as $keyword) if (str_contains($text, $keyword)) return $intent;
        return null;
    }

    /** Chuyển intent nội bộ thành payload ổn định gửi về webhook. */
    private function intentPayload(string $intent): string
    {
        return match ($intent) {
            'specs' => 'Khách muốn xem thông số kỹ thuật, trang bị và đặc điểm của mẫu xe đang được tư vấn.',
            'promotion' => 'Khách muốn hỏi ưu đãi và khuyến mãi hiện tại cho mẫu xe đang được tư vấn.',
            'price' => 'Khách muốn hỏi giá niêm yết hoặc giá lăn bánh của mẫu xe đang được tư vấn.',
            'finance' => 'Khách muốn hỏi phương án trả góp cho mẫu xe đang được tư vấn.',
            'documents' => 'Khách muốn biết hồ sơ và giấy tờ cần chuẩn bị để mua xe.',
            'colors' => 'Khách muốn hỏi mẫu xe đang được tư vấn còn những màu nào.',
            'test_drive' => 'Khách muốn đặt lịch lái thử hoặc hỏi điều kiện lái thử mẫu xe đang quan tâm.',
            'appointment' => 'Khách muốn đặt lịch hẹn để được Toyota Kiên Giang hỗ trợ.',
            'compare' => 'Khách muốn so sánh mẫu xe đang được tư vấn với mẫu xe khác.',
            'availability' => 'Khách muốn hỏi xe còn hàng hoặc thời gian giao xe.',
            'phone' => 'Khách muốn để lại số điện thoại để nhân viên Toyota Kiên Giang liên hệ tư vấn.',
            default => 'Khách muốn được tư vấn tiếp theo đúng nội dung nút đã chọn.',
        };
    }

    /** Cung cấp bảng từ khóa dùng để nhận diện intent quick reply. */
    private function keywords(): array
    {
        return ['specs' => ['thong so','trang bi','dong co','kich thuoc','noi that','ngoai that','an toan','tieu hao','option'],
            'promotion' => ['uu dai','khuyen mai','giam gia','qua tang','chuong trinh'], 'price' => ['gia','lan banh','bao gia','niem yet'],
            'finance' => ['tra gop','lai suat','vay','tra truoc','ngan hang','gop'], 'documents' => ['ho so','giay to','cccd','cmnd','thu tuc'],
            'colors' => ['mau','mau nao','mau xe'], 'test_drive' => ['lai thu','test drive'], 'appointment' => ['dat lich','lich hen','hen lich','showroom'],
            'compare' => ['so sanh','khac gi','hon gi'], 'availability' => ['con xe','con hang','giao xe','co san'],
            'phone' => ['so dien thoai','sdt','gui so','de lai so','goi lai','lien he']];
    }
}
