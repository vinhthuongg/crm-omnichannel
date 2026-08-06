<?php

namespace Modules\Message\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;

class OutboundErrorFormatter
{
    /** Rút gọn exception gửi tin thành mã lỗi, thông điệp và context an toàn để lưu/log. */
    public function format(\Throwable $exception): string
    {
        if (! $exception instanceof RequestException || ! $exception->response) return $exception->getMessage();
        $payload = $exception->response->json();
        $error = is_array($payload) ? (array) Arr::get($payload, 'error', []) : [];
        if ($error === []) return $exception->response->body() ?: $exception->getMessage();
        return trim(collect([Arr::get($error, 'message'), Arr::get($error, 'type') ? 'type='.Arr::get($error, 'type') : null,
            Arr::get($error, 'code') !== null ? 'code='.Arr::get($error, 'code') : null,
            Arr::get($error, 'error_subcode') !== null ? 'subcode='.Arr::get($error, 'error_subcode') : null,
            Arr::get($error, 'fbtrace_id') ? 'fbtrace_id='.Arr::get($error, 'fbtrace_id') : null])->filter()->implode(' | '));
    }
}
