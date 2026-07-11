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
        Schema::create('attendance_settings', function (Blueprint $table) {
            $table->id();
            $table->time('office_start_time')->default('09:00:00');
            $table->time('office_end_time')->default('18:00:00');
            $table->integer('grace_period_minutes')->default(15);
            $table->integer('late_threshold_minutes')->default(15);
            $table->integer('early_checkout_threshold_minutes')->default(30);
            $table->decimal('half_day_hours', 4, 2)->default(4.00);
            $table->json('weekend_days')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_settings');
    }
};
