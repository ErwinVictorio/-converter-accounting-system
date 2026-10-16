<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_dm_scopes', function (Blueprint $table) {
            $table->string('identity')->primary();
            $table->unsignedBigInteger('generation')->default(0);
        });
        Schema::create('purchase_dm_applications', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->unsignedBigInteger('user_id');
            $table->string('file_digest', 64);
            $table->text('filename');
            $table->json('scope');
            $table->json('details');
            $table->decimal('total_deduction', 15, 2);
            $table->timestamp('created_at');
            $table->timestamp('superseded_at')->nullable();
        });
        Schema::create('purchase_dm_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('purchase_dm_applications');
            $table->string('identity', 64)->unique();
            $table->string('reference');
            $table->string('payload_hash', 64);
        });
        Schema::create('purchase_dm_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('purchase_dm_applications');
            // Keep source IDs and snapshots after normal Purchase replacement.
            $table->unsignedBigInteger('vat_input_id')->index();
            $table->json('before');
            $table->json('after');
            $table->timestamp('retired_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_dm_sources');
        Schema::dropIfExists('purchase_dm_references');
        Schema::dropIfExists('purchase_dm_applications');
        Schema::dropIfExists('purchase_dm_scopes');
    }
};
