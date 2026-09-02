<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_vatsinputs', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_vatsinputs', 'document_type')) {
                $table->string('document_type', 20)->nullable()->after('document_no');
                $table->index(['reporting_period', 'customer_name', 'document_type'], 'sales_vatsinputs_period_customer_type_index');
            }
        });

        if (Schema::hasColumn('sales_vatsinputs', 'document_type')) {
            DB::table('sales_vatsinputs')
                ->whereNull('document_type')
                ->where('document_no', 'like', 'SI%')
                ->update(['document_type' => 'SI']);

            DB::table('sales_vatsinputs')
                ->whereNull('document_type')
                ->where('document_no', 'like', 'CM%')
                ->update(['document_type' => 'CM']);
        }
    }

    public function down(): void
    {
        Schema::table('sales_vatsinputs', function (Blueprint $table) {
            if (Schema::hasColumn('sales_vatsinputs', 'document_type')) {
                $table->dropIndex('sales_vatsinputs_period_customer_type_index');
                $table->dropColumn('document_type');
            }
        });
    }
};
