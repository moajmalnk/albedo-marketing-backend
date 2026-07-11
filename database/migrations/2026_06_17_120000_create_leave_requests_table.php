<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('leave_type', 100);
            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('total_days', 4, 1)->unsigned();
            $table->text('reason');
            $table->enum('status', ['Pending', 'Approved', 'Rejected', 'Discussion'])->default('Pending');
            $table->text('admin_comment')->nullable();
            $table->date('date_applied')->default(DB::raw('CURRENT_DATE'));
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
