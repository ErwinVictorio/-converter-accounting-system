<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_vatsinputs', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_vatsinputs', 'document_no')) {
                $table->string('document_no')->nullable();
            }

            if (! Schema::hasColumn('sales_vatsinputs', 'document_date')) {
                $table->date('document_date')->nullable();
            }

            if (! Schema::hasColumn('sales_vatsinputs', 'terms')) {
                $table->string('terms')->nullable();
            }

            if (! Schema::hasColumn('sales_vatsinputs', 'due_date')) {
                $table->date('due_date')->nullable();
            }

            if (! Schema::hasColumn('sales_vatsinputs', 'document_refs')) {
                $table->string('document_refs')->nullable();
            }

            if (! Schema::hasColumn('sales_vatsinputs', 'gross_amount')) {
                $table->decimal('gross_amount', 14, 2)->default(0);
            }

            if (! Schema::hasColumn('sales_vatsinputs', 'discount')) {
                $table->decimal('discount', 14, 2)->default(0);
            }

            if (! Schema::hasColumn('sales_vatsinputs', 'charges')) {
                $table->decimal('charges', 14, 2)->default(0);
            }

            if (! Schema::hasColumn('sales_vatsinputs', 'net_amount')) {
                $table->decimal('net_amount', 14, 2)->default(0);
            }

            if (! Schema::hasColumn('sales_vatsinputs', 'output_vat')) {
                $table->decimal('output_vat', 14, 2)->default(0);
            }

            if (! Schema::hasColumn('sales_vatsinputs', 'taxable_net_of_vat')) {
                $table->decimal('taxable_net_of_vat', 14, 2)->default(0);
            }

            if (! Schema::hasColumn('sales_vatsinputs', 'customer_tin')) {
                $table->string('customer_tin')->nullable();
            }

            if (! Schema::hasColumn('sales_vatsinputs', 'customer_type')) {
                $table->string('customer_type')->default('company');
            }

            if (! Schema::hasColumn('sales_vatsinputs', 'company_name')) {
                $table->string('company_name')->nullable();
            }

            if (! Schema::hasColumn('sales_vatsinputs', 'last_name')) {
                $table->string('last_name')->nullable();
            }

            if (! Schema::hasColumn('sales_vatsinputs', 'first_name')) {
                $table->string('first_name')->nullable();
            }

            if (! Schema::hasColumn('sales_vatsinputs', 'middle_name')) {
                $table->string('middle_name')->nullable();
            }

            if (! Schema::hasColumn('sales_vatsinputs', 'address1')) {
                $table->string('address1')->nullable();
            }

            if (! Schema::hasColumn('sales_vatsinputs', 'address2')) {
                $table->string('address2')->nullable();
            }

            if (! Schema::hasColumn('sales_vatsinputs', 'exempt_sales')) {
                $table->decimal('exempt_sales', 14, 2)->default(0);
            }

            if (! Schema::hasColumn('sales_vatsinputs', 'zero_rated_sales')) {
                $table->decimal('zero_rated_sales', 14, 2)->default(0);
            }

            if (! Schema::hasColumn('sales_vatsinputs', 'reporting_period')) {
                $table->date('reporting_period')->nullable();
            }

            if (! Schema::hasColumn('sales_vatsinputs', 'is_adjusted')) {
                $table->boolean('is_adjusted')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_vatsinputs', function (Blueprint $table) {
            $columns = [
                'document_no',
                'document_date',
                'terms',
                'due_date',
                'document_refs',
                'gross_amount',
                'discount',
                'charges',
                'net_amount',
                'output_vat',
                'taxable_net_of_vat',
                'customer_tin',
                'customer_type',
                'company_name',
                'last_name',
                'first_name',
                'middle_name',
                'address1',
                'address2',
                'exempt_sales',
                'zero_rated_sales',
                'reporting_period',
                'is_adjusted',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('sales_vatsinputs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
