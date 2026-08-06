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
        Schema::create('taxable_tb', function (Blueprint $table) {
            $table->id();
            $table->string('resgister_name');
            $table->string('supplier_name');
            $table->string('supplier_address');
            $table->decimal('amount_of_gross_purchase');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('taxable_tb');
    }
};
