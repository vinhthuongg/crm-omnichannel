<?php

namespace Modules\Notification\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FirebaseCredentials
{
    /** Đọc một trường service-account Firebase từ JSON credentials đã chuẩn hóa. */
    public function get(string $key): ?string
    {
        return $this->all()[$key] ?? config("services.firebase.{$key}");
    }

    /** Kiểm tra tính năng hoặc cấu hình hiện tại có đang được bật hay không. */
    public function enabled(): bool
    {
        return (bool) config('services.firebase.enabled') && filled($this->get('project_id'))
            && filled($this->get('client_email')) && filled($this->get('private_key'));
    }

    /** Trả OAuth token endpoint trong credentials hoặc endpoint Google mặc định. */
    public function tokenUri(): string { return (string) ($this->get('token_uri') ?: 'https://oauth2.googleapis.com/token'); }
    /** Đọc và chuẩn hóa private key từ thông tin xác thực Firebase. */
    public function privateKey(): string { return str_replace('\\n', "\n", (string) $this->get('private_key')); }

    /** Đọc và cache toàn bộ credentials từ JSON inline hoặc file cấu hình. */
    private function all(): array
    {
        return Cache::rememberForever('firebase.fcm.credentials', function (): array {
            $path = (string) config('services.firebase.credentials');
            if (blank($path)) return [];
            $resolved = $this->resolve($path);
            if (! $resolved) { Log::warning('Firebase credentials file is not readable', ['path' => $path]); return []; }
            $credentials = json_decode((string) file_get_contents($resolved), true);
            if (! is_array($credentials)) { Log::warning('Firebase credentials file is invalid JSON', ['path' => $resolved]); return []; }
            return $credentials;
        });
    }

    /** Chuẩn hóa đường dẫn credentials tương đối thành đường dẫn file tuyệt đối. */
    private function resolve(string $path): ?string
    {
        foreach ([$path, base_path($path), storage_path($path), storage_path('app/'.$path)] as $candidate)
            if (is_readable($candidate)) return $candidate;
        return null;
    }
}
