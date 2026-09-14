<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vat_inputs', function (Blueprint $table) {
            $table->boolean('uses_vat_bucket_amounts')->nullable()->after('is_imported');
            $table->decimal('purchase_imported_vat_amount', 14, 2)->nullable()->after('purchase_imported');
            $table->decimal('purchase_local_vat_amount', 14, 2)->nullable()->after('purchase_local');
            $table->decimal('services_vat_amount', 14, 2)->nullable()->after('services');
            $table->decimal('others_vat_amount', 14, 2)->nullable()->after('others');
        });

        Schema::table('purchase_adjustments', function (Blueprint $table) {
            $table->decimal('services_vat_amount', 14, 2)->nullable()->after('services');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_adjustments', function (Blueprint $table) {
            $table->dropColumn('services_vat_amount');
        });

        Schema::table('vat_inputs', function (Blueprint $table) {
            $table->dropColumn([
                'uses_vat_bucket_amounts',
                'purchase_imported_vat_amount',
                'purchase_local_vat_amount',
                'services_vat_amount',
                'others_vat_amount',
            ]);
        });
    }
};
