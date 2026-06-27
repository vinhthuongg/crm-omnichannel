<?php

namespace Modules\Chatbot\Services;

use Illuminate\Http\Client\Factory as Http;

class NvidiaNimClient
{
    public function __construct(private readonly Http $http)
    {
    }

    public function configured(): bool
    {
        return filled(config('chatbot.nim.api_key'));
    }

    public function embed(string $input): ?array
    {
        return $this->embedMany([$input])[0] ?? null;
    }

    public function embedMany(array $inputs): array
    {
        if (! $this->configured() || $inputs === []) {
            return [];
        }

        $response = $this->http
            ->connectTimeout(10)
            ->timeout(60)
            ->withToken((string) config('chatbot.nim.api_key'))
            ->post($this->url('/embeddings'), [
                'model' => config('chatbot.nim.embedding_model'),
                'input' => array_values($inputs),
            ]);

        $response->throw();

        return collect($response->json('data', []))
            ->sortBy('index')
            ->map(fn (array $item): array => array_map('floatval', (array) ($item['embedding'] ?? [])))
            ->filter()
            ->values()
            ->all();
    }

    public function chat(string $system, string $user): ?string
    {
        if (! $this->configured()) {
            return null;
        }

        $response = $this->http
            ->connectTimeout(10)
            ->timeout(60)
            ->withToken((string) config('chatbot.nim.api_key'))
            ->post($this->url('/chat/completions'), [
                'model' => config('chatbot.nim.chat_model'),
                'temperature' => 0.2,
                'max_tokens' => 450,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
            ]);

        $response->throw();

        return trim((string) data_get($response->json(), 'choices.0.message.content')) ?: null;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('chatbot.nim.base_url'), '/').$path;
    }
}
