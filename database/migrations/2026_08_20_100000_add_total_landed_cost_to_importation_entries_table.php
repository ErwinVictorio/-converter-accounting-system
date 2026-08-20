<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Total landed cost is what the customs paperwork actually states, so the
     * entry form now asks for it and derives charges + taxable goods from it.
     * It is input-only: the RELIEF "I" DAT has no field for it.
     */
    public function up(): void
    {
        Schema::table('importation_entries', function (Blueprint $table) {
            $table->decimal('total_landed_cost', 14, 2)->default(0)->after('country');
        });

        // Existing rows were captured with charges typed in directly.
        DB::table('importation_entries')->update([
            'total_landed_cost' => DB::raw('dutiable_value + charges'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('importation_entries', function (Blueprint $table) {
            $table->dropColumn('total_landed_cost');
        });
    }
};
