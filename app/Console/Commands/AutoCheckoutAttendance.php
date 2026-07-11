<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class AutoCheckoutAttendance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:auto-checkout';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically check out open attendance sessions at midnight';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting auto-checkout of open attendance sessions...');
        
        // Target time is typically 23:59:00 for the day.
        $checkoutTime = now();

        $openLogs = AttendanceLog::whereNull('check_out_at')->get();
        $count = 0;
        
        $supportsBreakTracking = Schema::hasColumn('attendance_logs', 'break_seconds')
            && Schema::hasColumn('attendance_logs', 'break_started_at');

        foreach ($openLogs as $log) {
            $breakSeconds = $supportsBreakTracking ? (int) $log->break_seconds : 0;
            if ($supportsBreakTracking && $log->break_started_at) {
                $breakSeconds += (int) max(0, $log->break_started_at->diffInSeconds($checkoutTime));
            }

            $updates = [
                'check_out_at' => $checkoutTime,
                'net_minutes' => (int) max(0, $log->check_in_at->diffInMinutes($checkoutTime) - ($breakSeconds / 60)),
                'is_final_session' => true,
                'auto_closed' => true,
            ];

            if ($supportsBreakTracking) {
                $updates['break_started_at'] = null;
                $updates['break_seconds'] = $breakSeconds;
                if (Schema::hasColumn('attendance_logs', 'break_last_ended_at')) {
                    $updates['break_last_ended_at'] = $log->break_started_at ? $checkoutTime : $log->break_last_ended_at;
                }
            }

            $log->update($updates);
            $count++;
        }

        $this->info("Auto-checked out {$count} sessions.");
    }
}
