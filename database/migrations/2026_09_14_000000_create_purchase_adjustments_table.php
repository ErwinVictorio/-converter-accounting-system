<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_vat_input_id')
                ->nullable()
                ->constrained('vat_inputs')
                ->nullOnDelete();
            $table->foreignId('target_vat_input_id')
                ->constrained('vat_inputs')
                ->cascadeOnDelete();
            $table->decimal('purchase_imported', 10, 2)->default(0);
            $table->decimal('purchase_local', 10, 2)->default(0);
            $table->decimal('services', 10, 2)->default(0);
            $table->decimal('others', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_adjustments');
    }
};
