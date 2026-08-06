<?php

namespace Modules\Customer\Services;

use App\Models\User;
use Modules\Conversation\Models\Tag;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerTag;

class CustomerProfileService
{
    /** Nhận CustomerAccessService để kiểm tra phạm vi truy cập. */
    public function __construct(private readonly CustomerAccessService $access) {}

    /** Bật/tắt trạng thái khách hàng tiềm năng và lưu người đánh dấu cùng thời điểm. */
    public function markPotential(User $user, Customer $customer, bool $potential): Customer
    {
        $customer = $this->access->accessible($user, $customer);
        $customer->forceFill(['is_potential' => $potential,
            'potential_marked_at' => $potential ? ($customer->potential_marked_at ?: now()) : null,
            'potential_marked_by' => $potential ? $user->id : null])->save();
        return $customer->fresh(['channels', 'tags', 'potentialMarkedBy']);
    }

    /** Kiểm tra quyền rồi gắn tag ID tồn tại vào hồ sơ khách hàng. */
    public function attachTag(User $user, Customer $customer, int $tagId): Customer
    {
        $customer = $this->access->accessible($user, $customer);
        $tag = Tag::query()->where('is_default', true)->findOrFail($tagId);
        $customerTag = CustomerTag::query()->firstOrCreate(['name' => $tag->name], ['color' => $tag->color ?: '#2563eb']);
        $customer->tags()->syncWithoutDetaching([$customerTag->id]);
        return $customer->fresh(['channels', 'tags', 'potentialMarkedBy']);
    }

    /** Kiểm tra quyền rồi gỡ tag ID khỏi hồ sơ khách hàng. */
    public function detachTag(User $user, Customer $customer, int $tagId): Customer
    {
        $customer = $this->access->accessible($user, $customer);
        $tag = CustomerTag::query()->find($tagId);
        if (! $tag && ($systemTag = Tag::query()->find($tagId))) $tag = CustomerTag::query()->where('name', $systemTag->name)->first();
        if ($tag) $customer->tags()->detach($tag->id);
        return $customer->fresh(['channels', 'tags', 'potentialMarkedBy']);
    }
}
