<?php

namespace App\Support;

use Modules\Conversation\Models\Conversation;

class InitialMessageTemplate
{
    private const SERVICE_MENU_MESSAGE = <<<'TEXT'
Kính chào Anh/Chị,

Toyota Kiên Giang cảm ơn Anh/Chị đã quan tâm đến sản phẩm và dịch vụ của chúng em.

Anh/Chị vui lòng chọn nhu cầu cần tư vấn bên dưới. Nếu thuận tiện, Anh/Chị có thể để lại số điện thoại hoặc Zalo để Toyota Kiên Giang liên hệ hỗ trợ nhanh và chính xác hơn ạ.
TEXT;

    public static function serviceMenuFor(?Conversation $conversation): string
    {
        if (! $conversation || filled($conversation->customer?->phone)) {
            return '';
        }

        $hasAgentReply = $conversation->messages()
            ->where('sender_type', 'user')
            ->where('message_type', '!=', 'whisper')
            ->where('channel', '!=', 'internal')
            ->exists();

        return $hasAgentReply ? '' : self::SERVICE_MENU_MESSAGE;
    }

    public static function messengerQuickReplies(): array
    {
        return [
            [
                'content_type' => 'text',
                'title' => 'Báo giá lăn bánh',
                'payload' => 'PRICE_BY_AREA',
            ],
            [
                'content_type' => 'text',
                'title' => 'Ưu đãi hiện hành',
                'payload' => 'PROMOTIONS',
            ],
            [
                'content_type' => 'text',
                'title' => 'Vay trả góp',
                'payload' => 'INSTALLMENT_LOAN',
            ],
            [
                'content_type' => 'text',
                'title' => 'Tình trạng xe',
                'payload' => 'VEHICLE_AVAILABILITY',
            ],
            [
                'content_type' => 'text',
                'title' => 'Chọn phiên bản',
                'payload' => 'VERSION_CONSULTING',
            ],
        ];
    }
}
