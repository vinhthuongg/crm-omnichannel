<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('botpress_conversation_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('botpress_user_id')->nullable()->index();
            $table->text('botpress_user_key')->nullable();
            $table->string('botpress_conversation_id')->nullable()->index();
            $table->string('last_botpress_message_id')->nullable();
            $table->json('last_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('botpress_conversation_links');
    }
};
