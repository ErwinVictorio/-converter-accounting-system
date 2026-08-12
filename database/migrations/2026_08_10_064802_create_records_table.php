<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vat_inputs', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_name');
            $table->string('tin_number')->nullable();
            $table->boolean('is_imported');
            $table->decimal('purchase_imported', 10, 2)->nullable();
            $table->decimal('purchase_local', 10, 2)->nullable();
            $table->decimal('services', 10, 2);
            $table->decimal('others', 10, 2)->nullable();
            $table->decimal('total', 10, 2);
            $table->date('date_uploaded'); 
            $table->boolean('is_broker')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('records');
    }
};
