<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Modules\Chatbot\Services\VectorIndexService;
use Modules\Search\Services\VectorSearchService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('chatbot:rebuild-vector-index', function (VectorIndexService $vectors) {
    $index = $vectors->rebuild();

    $this->info('Chatbot vector index rebuilt.');
    $this->line('Mode: '.($index['mode'] ?? 'unknown'));
    $this->line('Documents: '.count($index['documents'] ?? []));
})->purpose('Rebuild Toyota sales chatbot vector index');

Artisan::command('search:rebuild-vector-index {--customers : Rebuild customer search vectors only}', function (VectorSearchService $vectors) {
    $count = $vectors->rebuildCustomers();

    $this->info('CRM vector search index rebuilt.');
    $this->line('Customer documents: '.$count);
    $this->line('Provider: '.config('search.vector.provider'));
})->purpose('Rebuild CRM vector search documents');
