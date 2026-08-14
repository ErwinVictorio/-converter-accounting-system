<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
           $table->id();
            $table->string('name', 60);
            $table->string('payee', 60)->nullable();
            $table->string('addr', 100);
            $table->string('phone', 40);
            $table->string('mobile', 40);
            $table->string('email', 100);
            $table->string('contact', 100);
            $table->decimal('credit_limit', 12, 2)->default(0.00);
            $table->smallInteger('credit_terms')->default(0);
            $table->string('tin', 20);
            $table->tinyInteger('industry')->default(0);
            $table->tinyInteger('vattype')->default(1);
            $table->tinyInteger('exptax')->default(0);
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
