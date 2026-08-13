<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tạo bảng lưu cấu hình vận hành có thể chỉnh trực tiếp từ trang quản trị CRM. */
    public function up(): void
    {
        Schema::create('application_settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->json('value');
            $table->timestamps();
        });
    }

    /** Xóa bảng cấu hình ứng dụng khi rollback migration. */
    public function down(): void
    {
        Schema::dropIfExists('application_settings');
    }
};
