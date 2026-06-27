<?php

namespace App\Support;

use Modules\Conversation\Models\Conversation;

class InitialMessageTemplate
{
    private const PHONE_CAPTURE_MESSAGE = <<<'TEXT'
Kính chào Quý Anh/Chị,

Toyota Kiên Giang xin gửi đến Quý Anh/Chị lời chúc sức khỏe, hạnh phúc và thành công. Cảm ơn Quý Anh/Chị đã quan tâm đến các sản phẩm và dịch vụ của Toyota Kiên Giang.

Hiện tại, Toyota Kiên Giang đang hỗ trợ:

Báo giá lăn bánh mới nhất theo từng khu vực.
Cập nhật các chương trình ưu đãi và khuyến mãi hiện hành.
Tư vấn các gói vay trả góp với lãi suất phù hợp.
Kiểm tra tình trạng xe, màu xe và thời gian giao xe.
Tư vấn lựa chọn phiên bản phù hợp với nhu cầu và ngân sách.

Để em hỗ trợ Quý Anh/Chị nhanh chóng và chính xác nhất, Quý Anh/Chị vui lòng cho em xin số điện thoại hoặc Zalo. Em sẽ liên hệ trong thời gian sớm nhất để gửi báo giá, chương trình ưu đãi và tư vấn chi tiết theo đúng nhu cầu của mình.

Toyota Kiên Giang xin chân thành cảm ơn Quý Anh/Chị đã quan tâm. Kính chúc Quý Anh/Chị cùng gia đình luôn mạnh khỏe, hạnh phúc và thành công.
TEXT;

    public static function phoneCaptureFor(?Conversation $conversation): string
    {
        if (! $conversation || filled($conversation->customer?->phone)) {
            return '';
        }

        $hasAgentReply = $conversation->messages()
            ->where('sender_type', 'user')
            ->where('message_type', '!=', 'whisper')
            ->where('channel', '!=', 'internal')
            ->exists();

        return $hasAgentReply ? '' : self::PHONE_CAPTURE_MESSAGE;
    }
}
