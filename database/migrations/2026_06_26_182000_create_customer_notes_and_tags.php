<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('customer_tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('color', 24)->nullable();
            $table->timestamps();
        });

        Schema::create('customer_customer_tag', function (Blueprint $table): void {
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['customer_id', 'customer_tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_customer_tag');
        Schema::dropIfExists('customer_tags');
        Schema::dropIfExists('customer_notes');
    }
};
