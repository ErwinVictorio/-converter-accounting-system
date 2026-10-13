<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expanded_dm_applications', fn (Blueprint $table) => $table->timestamp('superseded_at')->nullable());
        Schema::create('expanded_dm_scopes', function (Blueprint $table) {
            $table->string('identity', 64)->primary();
            $table->unsignedBigInteger('generation')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expanded_dm_scopes');
        Schema::table('expanded_dm_applications', fn (Blueprint $table) => $table->dropColumn('superseded_at'));
    }
};
