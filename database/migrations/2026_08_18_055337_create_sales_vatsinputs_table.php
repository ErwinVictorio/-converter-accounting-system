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
        Schema::create('sales_vatsinputs', function (Blueprint $table) {
            $table->id();
            $table->string('document_no')->nullable();
            $table->date('document_date')->nullable();
            $table->string('terms')->nullable();
            $table->unsignedInteger('days')->nullable();
            $table->date('due_date')->nullable();
            $table->string('agent_name')->nullable();
            $table->string('customer_name');
            $table->string('document_refs')->nullable();
            $table->decimal('gross_amount', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('charges', 14, 2)->default(0);
            $table->decimal('net_amount', 14, 2)->default(0);
            $table->decimal('output_vat', 14, 2)->default(0);
            $table->decimal('taxable_net_of_vat', 14, 2)->default(0);
            $table->string('customer_tin')->nullable();
            $table->string('customer_type')->default('company');
            $table->string('company_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('address1')->nullable();
            $table->string('address2')->nullable();
            $table->decimal('exempt_sales', 14, 2)->default(0);
            $table->decimal('zero_rated_sales', 14, 2)->default(0);
            $table->date('reporting_period');
            $table->boolean('is_adjusted')->default(false);
            $table->timestamps();

            $table->index('reporting_period');
            $table->index('customer_name');
            $table->unique(['document_no', 'customer_name', 'reporting_period'], 'sales_vatsinputs_document_customer_period_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_vatsinputs');
    }
};
