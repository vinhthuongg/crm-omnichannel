<?php

namespace Modules\Notification\Services;

use Illuminate\Support\Facades\Cache;
use Modules\Notification\Infrastructure\FirebaseOAuthClient;

class FirebaseAccessTokenProvider
{
    /** Nhận FirebaseCredentials để cung cấp cấu hình và thông tin xác thực; FirebaseOAuthClient để giao tiếp với dịch vụ bên ngoài. */
    public function __construct(
        private readonly FirebaseCredentials $credentials,
        private readonly FirebaseOAuthClient $client,
    ) {}

    /** Ký JWT service account, đổi lấy OAuth token và cache token trong 50 phút. */
    public function token(): string
    {
        return Cache::remember('firebase.fcm.access_token', now()->addMinutes(50), function (): string {
            $now = time();
            $assertion = $this->encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$this->encode(json_encode([
                'iss' => $this->credentials->get('client_email'), 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $this->credentials->tokenUri(), 'iat' => $now, 'exp' => $now + 3600]));
            openssl_sign($assertion, $signature, $this->credentials->privateKey(), OPENSSL_ALGO_SHA256);
            return $this->client->exchange(
                $this->credentials->tokenUri(),
                $assertion.'.'.$this->encode($signature),
            );
        });
    }

    /** Mã hóa dữ liệu theo Base64 URL-safe để tạo JWT Firebase. */
    private function encode(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
}
