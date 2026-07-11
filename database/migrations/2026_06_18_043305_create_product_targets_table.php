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
        Schema::create('product_targets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('monthly_target')->default(0);
            $table->integer('month')->nullable();
            $table->integer('year')->nullable();
            $table->enum('status', ['Active', 'Deactivated'])->default('Active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_targets');
    }
};
