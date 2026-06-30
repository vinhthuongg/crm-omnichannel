<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Modules\Search\Services\VectorSearchService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('search:rebuild-vector-index {--customers : Rebuild customer search vectors only}', function (VectorSearchService $vectors) {
    $count = $vectors->rebuildCustomers();

    $this->info('CRM vector search index rebuilt.');
    $this->line('Customer documents: '.$count);
    $this->line('Provider: '.config('search.vector.provider'));
})->purpose('Rebuild CRM vector search documents');
