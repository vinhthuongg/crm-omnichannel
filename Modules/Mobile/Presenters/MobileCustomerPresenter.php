<?php

namespace Modules\Mobile\Presenters;

use App\Models\User;
use Modules\Conversation\Models\Tag;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerTag;

class MobileCustomerPresenter
{
    /** Tạo bản tóm tắt khách hàng gồm hồ sơ, kênh, sở thích và thời gian cập nhật. */
    public function summary(Customer $customer): array
    {
        return [...$this->information($customer), 'channels' => $this->channels($customer, true),
            'interests' => $this->interests($customer), 'created_at' => $customer->created_at?->toISOString(),
            'updated_at' => $customer->updated_at?->toISOString()];
    }

    /** Chia chi tiết khách hàng thành các nhóm thông tin, kênh liên hệ và sở thích. */
    public function detail(Customer $customer): array
    {
        return ['information' => $this->information($customer), 'channels' => $this->channels($customer), 'interests' => $this->interests($customer)];
    }

    /** Trả ID, tên, màu và trạng thái mặc định của một nhãn khách hàng. */
    public function tag(Tag $tag): array
    {
        return ['id' => (int) $tag->id, 'name' => $tag->name, 'color' => $tag->color, 'is_default' => (bool) $tag->is_default];
    }

    /** Định dạng dữ liệu information thành cấu trúc phản hồi dành cho ứng dụng mobile hoặc dashboard. */
    private function information(Customer $customer): array
    {
        return ['id' => (int) $customer->id, 'name' => $customer->name, 'avatar' => $customer->avatar,
            'phone' => $customer->phone, 'phone_collected_at' => $customer->phone_collected_at?->toISOString(), 'email' => $customer->email,
            'is_potential' => (bool) $customer->is_potential, 'potential_marked_at' => $customer->potential_marked_at?->toISOString(),
            'potential_marked_by' => $customer->potential_marked_by ? (int) $customer->potential_marked_by : null,
            'potential' => ['is_potential' => (bool) $customer->is_potential, 'marked_at' => $customer->potential_marked_at?->toISOString(),
                'marked_by' => $customer->relationLoaded('potentialMarkedBy') && $customer->potentialMarkedBy ? $this->agent($customer->potentialMarkedBy)
                    : ($customer->potential_marked_by ? ['id' => (int) $customer->potential_marked_by] : null)]];
    }

    /** Định dạng dữ liệu channels thành cấu trúc phản hồi dành cho ứng dụng mobile hoặc dashboard. */
    private function channels(Customer $customer, bool $metadata = false)
    {
        return $customer->channels->map(fn ($channel): array => ['id' => (int) $channel->id, 'channel' => $channel->channel,
            'external_id' => $channel->external_id] + ($metadata ? ['metadata' => $channel->metadata] : []))->values();
    }

    /** Ghép nhãn sở thích của khách với ID nhãn hệ thống để trả cho mobile. */
    private function interests(Customer $customer)
    {
        $systemIds = Tag::query()->whereIn('name', $customer->tags->pluck('name')->filter()->values())->pluck('id', 'name');
        return $customer->tags->map(fn (CustomerTag $tag): array => ['id' => (int) $tag->id, 'customer_tag_id' => (int) $tag->id,
            'tag_id' => (int) ($systemIds[$tag->name] ?? 0), 'source' => 'customer', 'name' => $tag->name, 'color' => $tag->color])
            ->filter(fn (array $tag): bool => filled($tag['name']))->unique(fn (array $tag): string => mb_strtolower($tag['name']))->values();
    }

    /** Trả hồ sơ, trạng thái và vai trò của nhân viên đánh dấu khách hàng tiềm năng. */
    private function agent(User $user): array
    {
        return ['id' => (int) $user->id, 'name' => $user->name, 'email' => $user->email, 'is_active' => (bool) $user->is_active,
            'roles' => $user->relationLoaded('roles') ? $user->roles->pluck('name')->values() : $user->getRoleNames()->values()];
    }
}
