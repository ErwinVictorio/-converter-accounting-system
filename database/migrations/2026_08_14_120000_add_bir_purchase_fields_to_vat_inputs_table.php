<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vat_inputs', function (Blueprint $table) {
            $table->string('vendor_type')->default('company')->after('tin_number');
            $table->string('company_name')->nullable()->after('vendor_type');
            $table->string('last_name')->nullable()->after('company_name');
            $table->string('first_name')->nullable()->after('last_name');
            $table->string('middle_name')->nullable()->after('first_name');
            $table->string('address1')->nullable()->after('middle_name');
            $table->string('address2')->nullable()->after('address1');
            $table->decimal('exempt', 14, 2)->default(0)->after('is_imported');
            $table->decimal('zero_rated', 14, 2)->default(0)->after('exempt');
            $table->decimal('capital_goods', 14, 2)->default(0)->after('services');
            $table->decimal('other_than_capital_goods', 14, 2)->default(0)->after('capital_goods');
            $table->decimal('taxable_net_of_vat', 14, 2)->default(0)->after('other_than_capital_goods');
            $table->decimal('vat_rate', 5, 2)->default(12)->after('taxable_net_of_vat');
            $table->decimal('input_vat', 14, 2)->default(0)->after('vat_rate');
            $table->decimal('total_purchases', 14, 2)->default(0)->after('input_vat');
        });
    }

    public function down(): void
    {
        Schema::table('vat_inputs', function (Blueprint $table) {
            $table->dropColumn([
                'vendor_type',
                'company_name',
                'last_name',
                'first_name',
                'middle_name',
                'address1',
                'address2',
                'exempt',
                'zero_rated',
                'capital_goods',
                'other_than_capital_goods',
                'taxable_net_of_vat',
                'vat_rate',
                'input_vat',
                'total_purchases',
            ]);
        });
    }
};
