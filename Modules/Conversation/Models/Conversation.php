<?php

namespace Modules\Conversation\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Customer\Models\Customer;
use Modules\Message\Models\ChatbotResponse;
use Modules\Message\Models\Message;

class Conversation extends Model
{
    protected $fillable = [
        'customer_id',
        'facebook_page_id',
        'external_conversation_id',
        'assigned_to',
        'assigned_by',
        'assigned_type',
        'work_shift_id',
        'owner_shift_id',
        'queue_shift_id',
        'claimed_at',
        'status',
        'last_message_at',
        'unread_messages_count',
        'last_read_at',
        'resolved_at',
        'first_response_at',
        'closed_at',
    ];

    /** Chuyển các mốc đọc, phân công, phản hồi, giải quyết và đóng thành datetime. */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'last_read_at' => 'datetime',
            'claimed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'first_response_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** Liên kết hội thoại với khách hàng sở hữu cuộc trao đổi. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Liên kết hội thoại với người dùng đang được giao xử lý. */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** Liên kết hội thoại với ca trực đã tiếp nhận ban đầu. */
    public function workShift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class);
    }

    /** Liên kết hội thoại đang chờ với ca trực chịu trách nhiệm tiếp theo. */
    public function queueShift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class, 'queue_shift_id');
    }

    /** Liên kết toàn bộ tin nhắn thuộc hội thoại. */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** Liên kết hội thoại với lịch sử các lần chatbot sinh câu trả lời. */
    public function chatbotResponses(): HasMany
    {
        return $this->hasMany(ChatbotResponse::class);
    }

    /** Liên kết các gợi ý trả lời đã sinh cho hội thoại. */
    public function replySuggestions(): HasMany
    {
        return $this->hasMany(ConversationReplySuggestion::class);
    }

    /** Liên kết nhiều-nhiều các nhãn phân loại hội thoại. */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /** Liên kết lịch sử phân công và thay đổi trạng thái của hội thoại. */
    public function activities(): HasMany
    {
        return $this->hasMany(ConversationActivity::class);
    }

    /** Liên kết những người dùng đã từng tham gia xử lý hội thoại. */
    public function handledUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_user_access')
            ->withPivot('first_handled_at')
            ->withTimestamps();
    }

    /** Đặt bộ đếm chưa đọc về 0 và lưu thời điểm đọc cho hội thoại/tin nhắn. */
    public function markAsRead(): void
    {
        $this->messages()
            ->where('sender_type', 'customer')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $this->forceFill([
            'unread_messages_count' => 0,
            'last_read_at' => now(),
        ])->save();
    }

    /** Tăng bộ đếm tin chưa đọc của hội thoại khi có tin mới. */
    public function incrementUnreadMessages(): void
    {
        $this->increment('unread_messages_count');
        $this->refresh();
    }
}
