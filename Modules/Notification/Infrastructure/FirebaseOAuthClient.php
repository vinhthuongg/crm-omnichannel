<?php

namespace Modules\Notification\Infrastructure;

use Illuminate\Support\Facades\Http;

class FirebaseOAuthClient
{
    /** Đổi JWT assertion của service account thành OAuth access token Firebase. */
    public function exchange(string $tokenUri, string $assertion): string
    {
        $response = Http::asForm()
            ->timeout((int) config('services.firebase.timeout', 10))
            ->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ])
            ->throw();

        return (string) $response->json('access_token');
    }
}
