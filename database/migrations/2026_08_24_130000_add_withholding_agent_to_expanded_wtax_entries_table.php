<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $needsIndex = ! Schema::hasColumn('expanded_wtax_entries', 'withholding_agent_tin');

        Schema::table('expanded_wtax_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('expanded_wtax_entries', 'withholding_agent_tin')) {
                $table->string('withholding_agent_tin', 9)->default('008791976')->after('reporting_period');
            }

            if (! Schema::hasColumn('expanded_wtax_entries', 'withholding_agent_branch_code')) {
                $table->string('withholding_agent_branch_code', 4)->default('0000')->after('withholding_agent_tin');
            }

            if (! Schema::hasColumn('expanded_wtax_entries', 'withholding_agent_name')) {
                $table->string('withholding_agent_name')->default('FORTRESS STEEL INC.')->after('withholding_agent_branch_code');
            }
        });

        if ($needsIndex) {
            Schema::table('expanded_wtax_entries', function (Blueprint $table) {
                $table->index(
                    ['withholding_agent_tin', 'withholding_agent_branch_code', 'reporting_period'],
                    'expanded_wtax_agent_period_index'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::table('expanded_wtax_entries', function (Blueprint $table) {
            $table->dropIndex('expanded_wtax_agent_period_index');
            $table->dropColumn([
                'withholding_agent_tin',
                'withholding_agent_branch_code',
                'withholding_agent_name',
            ]);
        });
    }
};
