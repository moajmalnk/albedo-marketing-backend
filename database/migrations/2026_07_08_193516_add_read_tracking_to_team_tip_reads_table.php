<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_tip_reads', function (Blueprint $table) {
            $table->timestamp('first_read_at')->nullable();
            $table->unsignedInteger('read_count')->default(1);
        });

        // Initialize first_read_at with existing read_at values
        DB::statement('UPDATE team_tip_reads SET first_read_at = read_at WHERE read_at IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('team_tip_reads', function (Blueprint $table) {
            $table->dropColumn(['first_read_at', 'read_count']);
        });
    }
};
