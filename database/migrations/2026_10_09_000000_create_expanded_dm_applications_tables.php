<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expanded_dm_applications', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->unsignedBigInteger('user_id');
            $table->string('file_digest', 64);
            $table->json('scope');
            $table->json('details');
            $table->decimal('total_deduction', 15, 2);
            $table->timestamp('created_at');
        });
        Schema::create('expanded_dm_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('expanded_dm_applications');
            $table->string('identity', 64)->unique();
            $table->string('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expanded_dm_references');
        Schema::dropIfExists('expanded_dm_applications');
    }
};
