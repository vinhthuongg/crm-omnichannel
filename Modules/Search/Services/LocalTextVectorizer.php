<?php

namespace Modules\Search\Services;

use Illuminate\Support\Str;

class LocalTextVectorizer
{
    /** Tạo hoặc so sánh vector văn bản tại bước vectorize. */
    public function vectorize(string $text): array
    {
        $dimensions = (int) config('search.vector.dimensions', 384);
        $vector = array_fill(0, $dimensions, 0.0);
        $normalized = Str::of($text)->lower()->ascii()->toString();
        preg_match_all('/[a-z0-9]+/u', $normalized, $matches);
        foreach ($matches[0] ?? [] as $token) $vector[abs(crc32($token)) % $dimensions] += 1.0;
        $norm = sqrt(array_sum(array_map(fn (float $value): float => $value * $value, $vector))) ?: 1.0;
        return array_map(fn (float $value): float => round($value / $norm, 8), $vector);
    }

    /** Tạo hoặc so sánh vector văn bản tại bước similarity. */
    public function similarity(array $a, array $b): float
    {
        $score = 0.0;
        for ($i = 0, $limit = min(count($a), count($b)); $i < $limit; $i++) $score += (float) $a[$i] * (float) $b[$i];
        return $score;
    }

    /** Tạo hoặc so sánh vector văn bản tại bước model. */
    public function model(): string { return 'local-hash-'.config('search.vector.dimensions', 384); }
}
