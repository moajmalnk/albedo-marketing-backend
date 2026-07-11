<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fix the auto-updating check_in_at bug in MySQL
        // By default, earlier MySQL versions add "ON UPDATE CURRENT_TIMESTAMP" to the first timestamp column
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->timestamp('check_in_at')->nullable()->change();
        });
        
        // Also fix the corrupted data if possible, though we might not know the exact original time.
        // But we can reset it to created_at since created_at was set when the row was created!
        DB::statement('UPDATE attendance_logs SET check_in_at = created_at WHERE check_in_at > created_at AND created_at IS NOT NULL');
    }

    public function down(): void
    {
        // No down migration needed
    }
};
