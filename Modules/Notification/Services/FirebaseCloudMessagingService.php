<?php

namespace Modules\Notification\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Notification\Models\FcmDeviceToken;

class FirebaseCloudMessagingService
{
    public function sendToUser(User $user, string $title, string $body, array $data = []): void
    {
        if (! $this->enabled()) {
            return;
        }

        $tokens = $user->fcmDeviceTokens()->pluck('token');

        foreach ($tokens as $token) {
            $this->sendToToken($token, $title, $body, $data);
        }
    }

    public function sendToToken(string $token, string $title, string $body, array $data = []): void
    {
        if (! $this->enabled()) {
            return;
        }

        $projectId = (string) $this->credential('project_id');
        $response = Http::withToken($this->accessToken())
            ->timeout((int) config('services.firebase.timeout', 10))
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                    'data' => $this->stringData($data),
                    'android' => [
                        'priority' => 'HIGH',
                        'notification' => [
                            'sound' => 'default',
                        ],
                    ],
                    'apns' => [
                        'payload' => [
                            'aps' => [
                                'sound' => 'default',
                            ],
                        ],
                    ],
                ],
            ]);

        if ($response->successful()) {
            return;
        }

        Log::warning('FCM push failed', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if (in_array($response->status(), [400, 404], true)) {
            FcmDeviceToken::query()->where('token', $token)->delete();
        }
    }

    public function enabled(): bool
    {
        return (bool) config('services.firebase.enabled')
            && filled($this->credential('project_id'))
            && filled($this->credential('client_email'))
            && filled($this->credential('private_key'));
    }

    private function accessToken(): string
    {
        return Cache::remember('firebase.fcm.access_token', now()->addMinutes(50), function (): string {
            $now = time();
            $assertion = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
                .'.'.
                $this->base64UrlEncode(json_encode([
                    'iss' => $this->credential('client_email'),
                    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                    'aud' => $this->tokenUri(),
                    'iat' => $now,
                    'exp' => $now + 3600,
                ]));

            openssl_sign($assertion, $signature, $this->privateKey(), OPENSSL_ALGO_SHA256);
            $jwt = $assertion.'.'.$this->base64UrlEncode($signature);

            $response = Http::asForm()
                ->timeout((int) config('services.firebase.timeout', 10))
                ->post($this->tokenUri(), [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ])
                ->throw();

            return (string) $response->json('access_token');
        });
    }

    private function privateKey(): string
    {
        return str_replace('\n', "\n", (string) $this->credential('private_key'));
    }

    private function credential(string $key): ?string
    {
        $credentials = $this->credentials();

        return $credentials[$key] ?? config("services.firebase.{$key}");
    }

    private function credentials(): array
    {
        return Cache::rememberForever('firebase.fcm.credentials', function (): array {
            $path = (string) config('services.firebase.credentials');

            if (blank($path)) {
                return [];
            }

            $resolvedPath = $this->resolveCredentialsPath($path);

            if (! $resolvedPath || ! is_readable($resolvedPath)) {
                Log::warning('Firebase credentials file is not readable', ['path' => $path]);

                return [];
            }

            $credentials = json_decode((string) file_get_contents($resolvedPath), true);

            if (! is_array($credentials)) {
                Log::warning('Firebase credentials file is invalid JSON', ['path' => $resolvedPath]);

                return [];
            }

            return $credentials;
        });
    }

    private function resolveCredentialsPath(string $path): ?string
    {
        $candidates = [
            $path,
            base_path($path),
            storage_path($path),
            storage_path('app/'.$path),
        ];

        foreach ($candidates as $candidate) {
            if (is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function tokenUri(): string
    {
        return (string) ($this->credential('token_uri') ?: 'https://oauth2.googleapis.com/token');
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function stringData(array $data): array
    {
        return collect($data)
            ->mapWithKeys(fn ($value, string $key): array => [$key => is_scalar($value) ? (string) $value : json_encode($value)])
            ->all();
    }
}
