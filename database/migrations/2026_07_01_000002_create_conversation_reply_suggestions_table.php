<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('conversation_reply_suggestions');

        Schema::create('conversation_reply_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->default('nvidia-nim');
            $table->json('suggestions');
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'message_id'], 'reply_suggestions_conversation_message_unique');
            $table->index(['conversation_id', 'generated_at'], 'reply_suggestions_conversation_generated_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_reply_suggestions');
    }
};
