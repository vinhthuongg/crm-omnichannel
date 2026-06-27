<?php

namespace Modules\Chatbot\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class VectorIndexService
{
    public function __construct(
        private readonly VehicleKnowledgeBase $knowledgeBase,
        private readonly NvidiaNimClient $nim,
    ) {
    }

    public function search(string $query, int $limit = 0): array
    {
        $index = $this->index();
        $documents = $index['documents'] ?? [];

        if ($documents === []) {
            return [];
        }

        $queryVector = $this->embed($query, (string) ($index['mode'] ?? 'local'));
        $limit = $limit > 0 ? $limit : (int) config('chatbot.vector.top_k', 6);

        return collect($documents)
            ->map(function (array $document) use ($queryVector): array {
                return [
                    ...$document,
                    'score' => $this->cosine($queryVector, (array) ($document['embedding'] ?? [])),
                ];
            })
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();
    }

    public function rebuild(): array
    {
        $documents = $this->knowledgeBase->documents();
        $mode = $this->nim->configured() ? 'nim' : 'local';
        try {
            $embeddings = $mode === 'nim'
                ? $this->nim->embedMany(array_column($documents, 'text'))
                : [];
        } catch (\Throwable) {
            $mode = 'local';
            $embeddings = [];
        }

        $documents = collect($documents)
            ->map(function (array $document, int $index) use ($embeddings, $mode): array {
                return [
                    ...$document,
                    'embedding' => $mode === 'nim' && isset($embeddings[$index])
                        ? $embeddings[$index]
                        : $this->localVector($document['text']),
                ];
            })
            ->values()
            ->all();

        $payload = [
            'mode' => $mode === 'nim' && $embeddings ? 'nim' : 'local',
            'generated_at' => now()->toISOString(),
            'documents' => $documents,
        ];

        File::ensureDirectoryExists(dirname($this->path()));
        file_put_contents($this->path(), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $payload;
    }

    private function index(): array
    {
        if (! is_file($this->path())) {
            return $this->rebuild();
        }

        $payload = json_decode((string) file_get_contents($this->path()), true) ?: [];
        $documentCount = count($this->knowledgeBase->documents());

        if (count((array) ($payload['documents'] ?? [])) !== $documentCount) {
            return $this->rebuild();
        }

        return $payload;
    }

    private function embed(string $text, string $mode): array
    {
        if ($mode === 'nim' && $this->nim->configured()) {
            try {
                return $this->nim->embed($text) ?: $this->localVector($text);
            } catch (\Throwable) {
                return $this->localVector($text);
            }
        }

        return $this->localVector($text);
    }

    private function localVector(string $text): array
    {
        $dimensions = (int) config('chatbot.vector.fallback_dimensions', 384);
        $vector = array_fill(0, $dimensions, 0.0);

        foreach ($this->tokens($text) as $token) {
            $index = abs(crc32($token)) % $dimensions;
            $vector[$index] += 1.0;
        }

        $norm = sqrt(array_sum(array_map(fn (float $value): float => $value * $value, $vector))) ?: 1.0;

        return array_map(fn (float $value): float => $value / $norm, $vector);
    }

    private function tokens(string $text): array
    {
        $text = Str::of($text)->lower()->ascii()->toString();
        preg_match_all('/[a-z0-9]+/u', $text, $matches);

        return $matches[0] ?? [];
    }

    private function cosine(array $a, array $b): float
    {
        $limit = min(count($a), count($b));
        $score = 0.0;

        for ($i = 0; $i < $limit; $i++) {
            $score += (float) $a[$i] * (float) $b[$i];
        }

        return $score;
    }

    private function path(): string
    {
        return (string) config('chatbot.vector.index_path');
    }
}
