<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vector_search_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('searchable_type');
            $table->unsignedBigInteger('searchable_id');
            $table->string('scope', 64)->default('default');
            $table->string('title')->nullable();
            $table->longText('content');
            $table->json('embedding');
            $table->string('embedding_provider', 32)->default('local');
            $table->string('embedding_model')->nullable();
            $table->string('content_hash', 64);
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();

            $table->unique(['searchable_type', 'searchable_id', 'scope'], 'vector_search_unique_subject_scope');
            $table->index(['searchable_type', 'searchable_id'], 'vector_search_subject_index');
            $table->index(['scope', 'indexed_at'], 'vector_search_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vector_search_documents');
    }
};
