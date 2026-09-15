<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_sales_uploads', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->date('reporting_period');
            $table->string('status', 20)->default('pending')->index();
            $table->json('issues')->nullable();
            $table->unsignedInteger('initial_customer_count')->default(0);
            $table->unsignedInteger('initial_row_count')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_sales_uploads');
    }
};
