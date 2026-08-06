<?php

namespace Modules\Notification\Infrastructure;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class FirebaseMessagingClient
{
    /** Gửi message payload đến Firebase Cloud Messaging HTTP v1 API. */
    public function send(string $projectId, string $accessToken, array $message): Response
    {
        return Http::withToken($accessToken)
            ->timeout((int) config('services.firebase.timeout', 10))
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => $message,
            ]);
    }
}
