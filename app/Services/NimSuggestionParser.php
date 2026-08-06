<?php

namespace App\Services;

use Illuminate\Support\Arr;

class NimSuggestionParser
{
    /** Phân tích phản hồi thô và trích xuất dữ liệu có cấu trúc. */
    public function parse(string $content): array
    {
        $json = trim($content);
        if (preg_match('/\{.*\}/s', $json, $matches)) $json = $matches[0];
        $decoded = json_decode($json, true);
        return collect(is_array($decoded) ? (array) Arr::get($decoded, 'suggestions', []) : [])
            ->filter(fn ($value): bool => is_scalar($value))->map(fn ($value): string => trim((string) $value))
            ->filter()->unique()->take(4)->values()->all();
    }
}
