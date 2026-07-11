<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->timestamp('break_started_at')->nullable()->after('check_out_at');
            $table->timestamp('break_last_ended_at')->nullable()->after('break_started_at');
            $table->unsignedInteger('break_minutes')->default(0)->after('break_last_ended_at');
            $table->boolean('is_final_session')->default(false)->after('session_number');
            $table->unsignedInteger('summary_leads_handled')->nullable()->after('is_final_session');
            $table->unsignedInteger('summary_calls_made')->nullable()->after('summary_leads_handled');
            $table->unsignedInteger('summary_conversions')->nullable()->after('summary_calls_made');
            $table->unsignedInteger('summary_followups_completed')->nullable()->after('summary_conversions');
            $table->text('summary_notes')->nullable()->after('summary_followups_completed');
            $table->text('summary_issues')->nullable()->after('summary_notes');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropColumn([
                'break_started_at',
                'break_last_ended_at',
                'break_minutes',
                'is_final_session',
                'summary_leads_handled',
                'summary_calls_made',
                'summary_conversions',
                'summary_followups_completed',
                'summary_notes',
                'summary_issues',
            ]);
        });
    }
};
