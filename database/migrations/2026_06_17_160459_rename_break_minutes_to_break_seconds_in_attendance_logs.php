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
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->renameColumn('break_minutes', 'break_seconds');
        });

        // Multiply existing minutes by 60
        \Illuminate\Support\Facades\DB::table('attendance_logs')->update([
            'break_seconds' => \Illuminate\Support\Facades\DB::raw('break_seconds * 60')
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Divide by 60 to revert to minutes
        \Illuminate\Support\Facades\DB::table('attendance_logs')->update([
            'break_seconds' => \Illuminate\Support\Facades\DB::raw('break_seconds / 60')
        ]);

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->renameColumn('break_seconds', 'break_minutes');
        });
    }
};
