<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_vatsinputs', function (Blueprint $table) {
            $table->boolean('s_zero_rated')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('sales_vatsinputs', function (Blueprint $table) {
            $table->dropColumn('s_zero_rated');
        });
    }
};
