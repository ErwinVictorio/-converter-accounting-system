<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trims expanded_wtax_entries down to the fields the BIR Excel format carries.
 *
 * Docs/1601EQ_Schedule_1_template.xls holds eleven columns and no others: there
 * is no transaction date and no Reference/PV/SI column, so the four dropped here
 * had no BIR-facing purpose. None of them ever reached a DAT --
 * ExpandedWtaxEntry::toBirExpandedRow() already omitted all four -- so removing
 * them changes no generated file.
 *
 * The new index covers the consolidation key: rows sharing reporting month, TIN,
 * ATC and rate are summed into one detail line, and both the records list and the
 * DAT download group on exactly those four columns.
 */
return new class extends Migration
{
    /**
     * Column values are not recoverable once dropped. That is acceptable because
     * re-uploading a month already replaces it wholesale, and these four were
     * import diagnostics rather than filed data.
     */
    public function up(): void
    {
        Schema::table('expanded_wtax_entries', function (Blueprint $table) {
            $table->dropColumn([
                'transaction_date',
                'source_no',
                'reference_no',
                'source_row',
            ]);
        });

        Schema::table('expanded_wtax_entries', function (Blueprint $table) {
            $table->index(
                ['reporting_period', 'payee_tin', 'atc_code', 'tax_rate'],
                'expanded_wtax_consolidation_index'
            );
        });
    }

    /**
     * Restores the structure so the migration is reversible, all four nullable
     * since the values themselves are gone.
     */
    public function down(): void
    {
        Schema::table('expanded_wtax_entries', function (Blueprint $table) {
            $table->dropIndex('expanded_wtax_consolidation_index');
        });

        Schema::table('expanded_wtax_entries', function (Blueprint $table) {
            $table->date('transaction_date')->nullable()->after('reporting_period');
            $table->string('source_no')->nullable()->after('transaction_date');
            $table->string('reference_no')->nullable()->after('source_no');
            $table->unsignedInteger('source_row')->nullable();
        });
    }
};
