<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasNameKey = Schema::hasColumn('customers', 'name_key');
        $hasCity = Schema::hasColumn('customers', 'city');

        Schema::table('customers', function (Blueprint $table) use ($hasNameKey, $hasCity) {
            if (! $hasNameKey) {
                $table->string('name_key', 300)->nullable()->after('name');
            }

            if (! $hasCity) {
                $table->string('city', 100)->nullable()->after('addr');
            }
        });

        DB::statement('ALTER TABLE customers MODIFY name VARCHAR(300) NOT NULL');
        DB::statement('ALTER TABLE customers MODIFY addr VARCHAR(500) NOT NULL');

        DB::table('customers')
            ->whereNull('name_key')
            ->orWhere('name_key', '')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->each(function ($customer): void {
                DB::table('customers')
                    ->where('id', $customer->id)
                    ->update([
                        'name_key' => preg_replace('/\s+/', '', strtoupper((string) $customer->name)),
                    ]);
            });

        if (! $hasNameKey) {
            Schema::table('customers', function (Blueprint $table) {
                $table->index('name_key', 'customers_name_key_index');
            });
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'name_key')) {
                $table->dropIndex('customers_name_key_index');
                $table->dropColumn('name_key');
            }

            if (Schema::hasColumn('customers', 'city')) {
                $table->dropColumn('city');
            }
        });
    }
};
