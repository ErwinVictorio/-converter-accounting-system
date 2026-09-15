<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_adjustments', function (Blueprint $table) {
            $table->decimal('purchase_local_vat_amount', 14, 2)->nullable()->after('purchase_local');
            $table->decimal('others_vat_amount', 14, 2)->nullable()->after('others');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_adjustments', function (Blueprint $table) {
            $table->dropColumn([
                'purchase_local_vat_amount',
                'others_vat_amount',
            ]);
        });
    }
};
