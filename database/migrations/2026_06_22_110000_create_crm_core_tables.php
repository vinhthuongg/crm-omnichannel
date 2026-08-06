<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** Tạo hoặc bổ sung bảng `users`, `customers`, `customer_channels`, `conversations`, `messages`, `tags`, `conversation_tag`, `activity_logs`, `notifications`; lưu các trường `is_active`, `password`, `name`, `avatar`, `phone`, `email`, `customer_id`, `channel`, `external_id`, `metadata` và các khóa/index cần thiết. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('password');
            }
        });

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('avatar')->nullable();
            $table->string('phone')->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('customer_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 32);
            $table->string('external_id');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['channel', 'external_id']);
        });

        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['open', 'pending', 'closed'])->default('open')->index();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('sender_type', 32);
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->string('channel', 32)->index();
            $table->text('content')->nullable();
            $table->string('message_type', 32)->default('text');
            $table->json('attachments')->nullable();
            $table->string('external_message_id')->nullable()->index();
            $table->timestamps();
            $table->index(['sender_type', 'sender_id']);
            $table->unique(['channel', 'external_message_id']);
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('color', 24)->nullable();
            $table->timestamps();
        });

        Schema::create('conversation_tag', function (Blueprint $table): void {
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['conversation_id', 'tag_id']);
        });

        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /** Hoàn tác các cột, khóa hoặc bảng `users`, `customers`, `customer_channels`, `conversations`, `messages`, `tags`, `conversation_tag`, `activity_logs`, `notifications` đã được migration này tạo. */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('conversation_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('customer_channels');
        Schema::dropIfExists('customers');
        Schema::table('users', fn (Blueprint $table) => Schema::hasColumn('users', 'is_active') ? $table->dropColumn('is_active') : null);
    }
};
