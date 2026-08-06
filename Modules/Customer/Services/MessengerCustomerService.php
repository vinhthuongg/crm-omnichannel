<?php

namespace Modules\Customer\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\Tag;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Customer\Models\CustomerTag;

class MessengerCustomerService
{
    /** Nhận ConversationVisibilityService để kiểm tra phạm vi truy cập. */
    public function __construct(private readonly ConversationVisibilityService $visibility) {}

    /** Cập nhật tên, email, số điện thoại hoặc thông tin hồ sơ của khách hàng trong hội thoại được phép xem. */
    public function update(User $user, Conversation $conversation, array $data): Conversation
    {
        $this->authorize($user, $conversation);
        $conversation->loadMissing('customer.channels');
        if (! $conversation->customer) throw (new ModelNotFoundException)->setModel(\Modules\Customer\Models\Customer::class);
        $conversation->customer->forceFill(['name' => trim($data['name']),
            'phone' => filled($data['phone'] ?? null) ? trim($data['phone']) : null,
            'email' => filled($data['email'] ?? null) ? trim($data['email']) : null])->save();
        return $conversation->load(['customer.channels', 'customer.notes.user', 'customer.tags', 'tags']);
    }

    /** Thêm ghi chú nội bộ cho khách hàng và lưu người tạo ghi chú. */
    public function addNote(User $user, Conversation $conversation, string $body): Conversation
    {
        $this->authorize($user, $conversation);
        $conversation->loadMissing('customer');
        $conversation->customer?->notes()->create(['user_id' => $user->id, 'body' => $body]);
        return $conversation->load('customer.notes.user');
    }

    /** Tạo hoặc lấy nhãn theo tên rồi gắn nhãn đó vào khách hàng. */
    public function addTag(User $user, Conversation $conversation, string $name): Conversation
    {
        $this->authorize($user, $conversation);
        $name = trim($name);
        if ($name === '') throw ValidationException::withMessages(['name' => 'Tag không được để trống.']);
        $system = Tag::query()->where('name', $name)->first();
        if (! $system) throw ValidationException::withMessages(['name' => 'Tag này chưa có trong hệ thống.']);
        $tag = CustomerTag::query()->firstOrCreate(['name' => $system->name], ['color' => $system->color ?: '#2563eb']);
        $conversation->loadMissing('customer');
        $conversation->customer?->tags()->syncWithoutDetaching([$tag->id]);
        return $conversation->load('customer.tags');
    }

    /** Từ chối cập nhật khi người dùng không có quyền xem hội thoại chứa khách hàng. */
    private function authorize(User $user, Conversation $conversation): void
    {
        if (! $this->visibility->canView($user, $conversation)) {
            throw new AuthorizationException;
        }
    }
}
