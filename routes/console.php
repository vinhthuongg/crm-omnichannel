<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Modules\Chatbot\Services\VectorIndexService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('chatbot:rebuild-vector-index', function (VectorIndexService $vectors) {
    $index = $vectors->rebuild();

    $this->info('Chatbot vector index rebuilt.');
    $this->line('Mode: '.($index['mode'] ?? 'unknown'));
    $this->line('Documents: '.count($index['documents'] ?? []));
})->purpose('Rebuild Toyota sales chatbot vector index');
