<?php

namespace Modules\Search\Services;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Chatbot\Services\NvidiaNimClient;
use Modules\Customer\Models\Customer;
use Modules\Search\Models\VectorSearchDocument;

class VectorSearchService
{
    public function __construct(private readonly NvidiaNimClient $nim)
    {
    }

    public function rebuildCustomers(?Collection $customers = null): int
    {
        $count = 0;
        $query = Customer::query()
            ->whereHas('conversations')
            ->with([
                'channels',
                'tags',
                'notes' => fn ($query) => $query->latest()->limit(10),
                'conversations.messages' => fn ($query) => $query->latest()->limit((int) config('search.vector.customer_message_limit', 40)),
            ]);

        if ($customers) {
            $query->whereIn('id', $customers->pluck('id'));
        }

        $query->chunkById(100, function (EloquentCollection $customers) use (&$count): void {
            foreach ($customers as $customer) {
                $this->indexCustomer($customer);
                $count++;
            }
        });

        return $count;
    }

    public function indexCustomer(Customer $customer): VectorSearchDocument
    {
        $customer->loadMissing([
            'channels',
            'tags',
            'notes' => fn ($query) => $query->latest()->limit(10),
            'conversations.messages' => fn ($query) => $query->latest()->limit((int) config('search.vector.customer_message_limit', 40)),
        ]);

        $content = $this->customerContent($customer);
        $hash = hash('sha256', $content);
        $document = VectorSearchDocument::query()->firstOrNew([
            'searchable_type' => Customer::class,
            'searchable_id' => $customer->id,
            'scope' => 'customer',
        ]);

        if ($document->exists && $document->content_hash === $hash) {
            return $document;
        }

        $mode = $this->provider();
        $embedding = $this->embed($content, $mode);

        $document->fill([
            'title' => $customer->name,
            'content' => $content,
            'embedding' => $embedding,
            'embedding_provider' => $mode,
            'embedding_model' => $mode === 'nim'
                ? (string) config('chatbot.nim.embedding_model')
                : 'local-hash-'.config('search.vector.dimensions', 384),
            'content_hash' => $hash,
            'indexed_at' => now(),
        ])->save();

        return $document;
    }

    public function searchCustomers(string $query, int $limit = 50): array
    {
        if (! config('search.vector.enabled', true) || trim($query) === '') {
            return [];
        }

        $documents = VectorSearchDocument::query()
            ->where('scope', 'customer')
            ->where('searchable_type', Customer::class)
            ->get();

        if ($documents->isEmpty()) {
            return [];
        }

        $queryVector = $this->embed($query, (string) ($documents->first()->embedding_provider ?: $this->provider()));
        $minScore = (float) config('search.vector.min_score', 0.08);

        return $documents
            ->map(fn (VectorSearchDocument $document): array => [
                'customer_id' => (int) $document->searchable_id,
                'score' => $this->cosine($queryVector, (array) $document->embedding),
            ])
            ->filter(fn (array $item): bool => $item['score'] >= $minScore)
            ->sortByDesc('score')
            ->take($limit)
            ->pluck('customer_id')
            ->values()
            ->all();
    }

    private function customerContent(Customer $customer): string
    {
        $parts = [
            'Khach hang: '.$customer->name,
            'So dien thoai: '.($customer->phone ?: ''),
            'Email: '.($customer->email ?: ''),
            'Kenh: '.$customer->channels->map(fn ($channel): string => $channel->channel.' '.$channel->external_id)->implode('; '),
            'Tag: '.$customer->tags->pluck('name')->implode('; '),
            'Ghi chu: '.$customer->notes->pluck('body')->implode('; '),
            'Noi dung hoi thoai: '.$customer->conversations
                ->flatMap(fn ($conversation) => $conversation->messages)
                ->sortByDesc('created_at')
                ->take((int) config('search.vector.customer_message_limit', 40))
                ->map(fn ($message): string => trim(($message->sender_type === 'customer' ? 'Khach: ' : 'Nhan vien: ').(string) $message->content))
                ->filter()
                ->implode(' | '),
        ];

        return collect($parts)
            ->map(fn (string $part): string => Str::squish($part))
            ->filter()
            ->implode("\n");
    }

    private function provider(): string
    {
        $provider = (string) config('search.vector.provider', 'local');

        return $provider === 'nim' && $this->nim->configured() ? 'nim' : 'local';
    }

    private function embed(string $text, string $provider): array
    {
        if ($provider === 'nim' && $this->nim->configured()) {
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
        $dimensions = (int) config('search.vector.dimensions', 384);
        $vector = array_fill(0, $dimensions, 0.0);

        foreach ($this->tokens($text) as $token) {
            $index = abs(crc32($token)) % $dimensions;
            $vector[$index] += 1.0;
        }

        $norm = sqrt(array_sum(array_map(fn (float $value): float => $value * $value, $vector))) ?: 1.0;

        return array_map(fn (float $value): float => round($value / $norm, 8), $vector);
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
}
