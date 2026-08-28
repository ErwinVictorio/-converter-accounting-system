<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expanded_wtax_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('expanded_wtax_entries', 'report_type')) {
                $table->string('report_type', 20)->default('quarterly')->after('reporting_period');
            }
        });

        Schema::table('expanded_wtax_entries', function (Blueprint $table) {
            $table->index(
                ['report_type', 'withholding_agent_tin', 'withholding_agent_branch_code', 'reporting_period'],
                'expanded_wtax_report_agent_period_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('expanded_wtax_entries', function (Blueprint $table) {
            $table->dropIndex('expanded_wtax_report_agent_period_index');
        });

        Schema::table('expanded_wtax_entries', function (Blueprint $table) {
            if (Schema::hasColumn('expanded_wtax_entries', 'report_type')) {
                $table->dropColumn('report_type');
            }
        });
    }
};
