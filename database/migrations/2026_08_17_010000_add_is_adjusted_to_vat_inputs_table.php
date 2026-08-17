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
        Schema::table('vat_inputs', function (Blueprint $table) {
            if (! Schema::hasColumn('vat_inputs', 'is_adjusted')) {
                $table->boolean('is_adjusted')->default(false)->after('is_broker');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vat_inputs', function (Blueprint $table) {
            if (Schema::hasColumn('vat_inputs', 'is_adjusted')) {
                $table->dropColumn('is_adjusted');
            }
        });
    }
};
