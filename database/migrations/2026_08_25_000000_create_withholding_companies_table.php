<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Withholding agent companies the Expanded WTAX module files for.
 *
 * Before this table the "Known Company" dropdown could only offer what
 * config/bir.php hard-codes plus whatever agent TINs happened to be sitting in
 * expanded_wtax_entries, so a new company could not be registered until after a
 * month had already been uploaded under it.
 *
 * tin + branch_code is the identity, matching the DAT: one 1601EQ file is filed
 * per agent TIN and branch per month, and that pair is what expanded_wtax_entries
 * stores on every row. No foreign key to it on purpose -- uploaded rows keep the
 * TIN and branch they were filed under even if the company row is later removed,
 * which is also why the UI deactivates rather than deletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('withholding_companies')) {
            return;
        }

        Schema::create('withholding_companies', function (Blueprint $table) {
            $table->id();
            // Stored already normalised: 9 digits, and the branch left-padded to 4.
            $table->string('tin', 9);
            $table->string('branch_code', 4)->default('0000');
            $table->string('registered_name');
            $table->string('trade_name')->nullable();
            $table->string('rdo_code', 3)->nullable();
            $table->string('address1')->nullable();
            $table->string('address2')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tin', 'branch_code'], 'withholding_companies_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withholding_companies');
    }
};
