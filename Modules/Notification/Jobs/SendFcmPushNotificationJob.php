<?php

namespace Modules\Notification\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as DispatchableQueueable;
use Modules\Notification\Services\FirebaseCloudMessagingService;

class SendFcmPushNotificationJob implements ShouldQueue
{
    use DispatchableQueueable;
    use Queueable;

    public function __construct(
        private readonly array $userIds,
        private readonly string $title,
        private readonly string $body,
        private readonly array $data = [],
    )
    {
    }

    public function handle(FirebaseCloudMessagingService $fcm): void
    {
        User::query()
            ->whereIn('id', $this->userIds)
            ->with('fcmDeviceTokens')
            ->get()
            ->each(fn (User $user): mixed => $fcm->sendToUser($user, $this->title, $this->body, $this->data));
    }
}
