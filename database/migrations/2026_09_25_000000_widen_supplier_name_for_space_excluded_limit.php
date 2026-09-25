<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('name', 300)->change();
        });
    }

    public function down(): void
    {
        // Keep the widened column: shrinking could truncate saved names.
    }
};
