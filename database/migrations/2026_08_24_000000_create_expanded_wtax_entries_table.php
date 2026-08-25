<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expanded withholding tax rows, kept in their own table rather than in
 * vat_inputs: these are 1604E figures, not VAT, and merging them would pull
 * withholding amounts into the purchase DAT and the VAT input totals.
 *
 * One row per payee per ATC per month. The source Excel gives a tax-withheld
 * amount per rate column, so a single spreadsheet line can produce several rows
 * here -- the reference DAT shows the same payee twice under different codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expanded_wtax_entries', function (Blueprint $table) {
            $table->id();
            $table->date('reporting_period');
            $table->string('withholding_agent_tin', 9)->default('008791976');
            $table->string('withholding_agent_branch_code', 4)->default('0000');
            $table->string('withholding_agent_name')->default('FORTRESS STEEL INC.');
            $table->date('transaction_date')->nullable();
            // Excel "No" (payment voucher) and "Reference" (invoice), kept for tracing
            // a generated line back to the spreadsheet row it came from.
            $table->string('source_no')->default('');
            $table->string('reference_no')->default('');
            $table->string('payee_name');
            $table->string('payee_type')->default('company');
            $table->string('payee_tin')->default('');
            // Always 0000 in the reference DAT, even for payees whose TIN carries a
            // branch suffix, so it is stored rather than derived from the TIN.
            $table->string('payee_branch_code', 4)->default('0000');
            $table->string('company_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('middle_name')->nullable();
            // Nullable so a row whose rate has no mapping is still stored and
            // reported as an issue, instead of failing the whole upload.
            $table->string('atc_code')->nullable();
            $table->decimal('tax_rate', 5, 2);
            $table->decimal('income_payment', 15, 2);
            $table->decimal('tax_withheld', 15, 2);
            $table->unsignedInteger('source_row')->nullable();
            $table->timestamps();

            $table->index('reporting_period');
            $table->index(
                ['withholding_agent_tin', 'withholding_agent_branch_code', 'reporting_period'],
                'expanded_wtax_agent_period_index'
            );
            $table->index('payee_tin');
            $table->index('atc_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expanded_wtax_entries');
    }
};
