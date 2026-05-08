<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function checkIn(Request $request)
    {
        $data = $request->validate(['work_mode' => ['required', 'in:OFFICE,WFH']]);
        $log = AttendanceLog::query()->create([
            'user_id' => $request->user()->id,
            'work_mode' => $data['work_mode'],
            'check_in_at' => now(),
            'day_date' => now()->toDateString(),
            'session_number' => 1,
        ]);
        return response()->json($log, 201);
    }

    public function checkOut(Request $request)
    {
        $log = AttendanceLog::query()->where('user_id', $request->user()->id)->whereDate('day_date', now()->toDateString())->whereNull('check_out_at')->latest()->firstOrFail();
        $log->update(['check_out_at' => now(), 'net_minutes' => $log->check_in_at->diffInMinutes(now())]);
        return response()->json($log->fresh());
    }

    public function today(Request $request)
    {
        $today = AttendanceLog::query()->where('user_id', $request->user()->id)->whereDate('day_date', now()->toDateString())->latest()->get();
        return response()->json($today);
    }
}
