<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('text_conversation_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('text_chat_id')->index();
            $table->string('text_thread_id')->nullable()->index();
            $table->string('text_customer_id')->nullable()->index();
            $table->string('facebook_page_id')->nullable()->index();
            $table->string('facebook_psid')->nullable()->index();
            $table->timestamp('bot_paused_at')->nullable();
            $table->json('last_payload')->nullable();
            $table->timestamps();

            $table->unique('text_chat_id');
            $table->unique('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('text_conversation_links');
    }
};
