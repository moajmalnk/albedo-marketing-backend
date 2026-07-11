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
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('activity_id');
            $table->dateTime('scheduled_at');
            $table->enum('student_profile', ['AVG', 'WEAK', 'BRIGHT'])->nullable();
            $table->enum('parent_availability', ['MC', 'FC', 'FMC'])->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['booked', 'done', 'no_show', 'cancelled'])->default('booked');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assessments');
    }
};
