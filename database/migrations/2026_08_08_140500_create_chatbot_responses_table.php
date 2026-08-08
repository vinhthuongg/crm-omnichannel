<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tạo bảng audit một lần sinh chatbot cho mỗi tin khách, gồm trạng thái, từng bubble và mã request. */
    public function up(): void
    {
        Schema::create('chatbot_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_message_id')->unique()->constrained('messages')->cascadeOnDelete();
            $table->string('external_message_id')->unique();
            $table->string('request_id')->nullable()->index();
            $table->string('chatbot_message_id')->nullable()->index();
            $table->string('status', 24)->default('pending')->index();
            $table->json('segments')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /** Xóa bảng audit chatbot khi rollback migration. */
    public function down(): void
    {
        Schema::dropIfExists('chatbot_responses');
    }
};
