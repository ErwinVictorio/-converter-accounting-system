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
        Schema::create('importation_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sequence_number')->nullable();
            $table->date('tax_month');
            $table->string('import_entry_no');
            $table->date('assessment_date');
            $table->string('supplier');
            $table->date('importation_date');
            $table->string('country');
            $table->decimal('dutiable_value', 14, 2)->default(0);
            $table->decimal('charges', 14, 2)->default(0);
            $table->decimal('exempt', 14, 2)->default(0);
            $table->decimal('taxable_goods', 14, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(12);
            $table->decimal('vat_payable', 14, 2)->default(0);
            $table->string('or_number');
            $table->date('payment_date');
            $table->foreignId('vat_input_id')->nullable()->constrained('vat_inputs')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tax_month', 'import_entry_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('importation_entries');
    }
};
