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
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->enum('work_mode', ['OFFICE', 'WFH']);
            $table->timestamp('check_in_at');
            $table->timestamp('check_out_at')->nullable();
            $table->integer('net_minutes')->nullable();
            $table->unsignedTinyInteger('session_number')->default(1);
            $table->date('day_date');
            $table->timestamps();
            $table->index(['user_id', 'day_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
