<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['vat_inputs', 'pending_purchase_uploads'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->string('filing_company_tin', 9)->nullable();
                $table->string('filing_company_branch_code', 4)->nullable();
                $table->index(['filing_company_tin', 'filing_company_branch_code', $name === 'vat_inputs' ? 'date_uploaded' : 'reporting_period'], $name.'_filing_period');
            });
        }
        Schema::create('purchase_upload_locks', function (Blueprint $table) {
            $table->string('identity', 32)->primary();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve Purchase ownership; restore a company-aware application version instead.');
    }
};
