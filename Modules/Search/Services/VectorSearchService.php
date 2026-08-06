<?php

namespace Modules\Search\Services;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Modules\Customer\Models\Customer;
use Modules\Search\Models\VectorSearchDocument;

class VectorSearchService
{
    /** Nhận CustomerSearchContentBuilder để tạo dữ liệu theo cấu hình; LocalTextVectorizer để biến văn bản thành vector và tính độ tương đồng. */
    public function __construct(private readonly CustomerSearchContentBuilder $content, private readonly LocalTextVectorizer $vectors) {}

    /** Thực hiện lập chỉ mục hoặc tìm kiếm vector khách hàng tại bước rebuildCustomers. */
    public function rebuildCustomers(?Collection $customers = null): int
    {
        $count = 0;
        $query = Customer::query()->whereHas('conversations')->with($this->relations());
        if ($customers) $query->whereIn('id', $customers->pluck('id'));
        $query->chunkById(100, function (EloquentCollection $customers) use (&$count): void {
            foreach ($customers as $customer) { $this->indexCustomer($customer); $count++; }
        });
        return $count;
    }

    /** Thực hiện lập chỉ mục hoặc tìm kiếm vector khách hàng tại bước indexCustomer. */
    public function indexCustomer(Customer $customer): VectorSearchDocument
    {
        $customer->loadMissing($this->relations());
        $content = $this->content->build($customer);
        $hash = hash('sha256', $content);
        $document = VectorSearchDocument::query()->firstOrNew(['searchable_type' => Customer::class,
            'searchable_id' => $customer->id, 'scope' => 'customer']);
        if ($document->exists && $document->content_hash === $hash) return $document;
        $document->fill(['title' => $customer->name, 'content' => $content, 'embedding' => $this->vectors->vectorize($content),
            'embedding_provider' => 'local', 'embedding_model' => $this->vectors->model(), 'content_hash' => $hash, 'indexed_at' => now()])->save();
        return $document;
    }

    /** Thực hiện lập chỉ mục hoặc tìm kiếm vector khách hàng tại bước searchCustomers. */
    public function searchCustomers(string $query, int $limit = 50): array
    {
        if (! config('search.vector.enabled', true) || trim($query) === '') return [];
        $documents = VectorSearchDocument::query()->where('scope', 'customer')->where('searchable_type', Customer::class)->get();
        if ($documents->isEmpty()) return [];
        $queryVector = $this->vectors->vectorize($query);
        $minScore = (float) config('search.vector.min_score', 0.08);
        return $documents->map(fn (VectorSearchDocument $document): array => ['customer_id' => (int) $document->searchable_id,
            'score' => $this->vectors->similarity($queryVector, (array) $document->embedding)])
            ->filter(fn (array $item): bool => $item['score'] >= $minScore)->sortByDesc('score')->take($limit)
            ->pluck('customer_id')->values()->all();
    }

    /** Thực hiện lập chỉ mục hoặc tìm kiếm vector khách hàng tại bước relations. */
    private function relations(): array
    {
        return ['channels', 'tags', 'notes' => fn ($query) => $query->latest()->limit(10),
            'conversations.messages' => fn ($query) => $query->latest()->limit((int) config('search.vector.customer_message_limit', 40))];
    }
}
