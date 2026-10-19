<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'importation_entries_tax_month_import_entry_no_unique';

    private const LOOKUP_INDEX = 'importation_entries_tax_month_import_entry_no_index';

    public function up(): void
    {
        Schema::table('importation_entries', function (Blueprint $table) {
            $table->dropUnique(self::UNIQUE_INDEX);
            $table->index(['tax_month', 'import_entry_no'], self::LOOKUP_INDEX);
        });
    }

    public function down(): void
    {
        $duplicate = DB::table('importation_entries')
            ->select('tax_month', 'import_entry_no')
            ->groupBy('tax_month', 'import_entry_no')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException(
                'Cannot restore the unique Importation Entry No. index: duplicate entries exist for '
                .$duplicate->tax_month.' / '.$duplicate->import_entry_no.'. Remove or resolve duplicates before rolling back.'
            );
        }

        Schema::table('importation_entries', function (Blueprint $table) {
            $table->dropIndex(self::LOOKUP_INDEX);
            $table->unique(['tax_month', 'import_entry_no'], self::UNIQUE_INDEX);
        });
    }
};
