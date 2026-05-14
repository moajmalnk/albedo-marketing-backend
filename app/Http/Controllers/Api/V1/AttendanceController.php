<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Daily roster + attendance for admin / dept head (read-only).
     */
    public function reports(Request $request)
    {
        $this->ensureAttendanceReportAccess($request);

        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'department' => ['nullable', 'in:all,PM,IM'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $date = CarbonImmutable::parse($validated['date'] ?? now()->toDateString(), config('app.timezone'));
        $department = $validated['department'] ?? 'all';

        $roster = $this->rosterUsersQuery($request, $department)->orderBy('first_name')->orderBy('last_name')->get();

        $latestIds = AttendanceLog::query()
            ->selectRaw('MAX(id) as id')
            ->whereDate('day_date', $date->toDateString())
            ->whereIn('user_id', $roster->pluck('id'))
            ->groupBy('user_id')
            ->pluck('id');

        $logsByUserId = AttendanceLog::query()
            ->whereIn('id', $latestIds)
            ->get()
            ->keyBy('user_id');

        $lateThreshold = $this->lateThresholdForDate($date);

        $rows = $roster->map(function (User $user) use ($logsByUserId, $lateThreshold) {
            $log = $logsByUserId->get($user->id);
            $isLate = $log ? $log->check_in_at->gt($lateThreshold) : false;

            return [
                'user_id' => $user->id,
                'name' => trim($user->first_name.' '.($user->last_name ?? '')),
                'department' => $user->department ?? '',
                'check_in_at' => $log?->check_in_at?->toIso8601String(),
                'check_out_at' => $log?->check_out_at?->toIso8601String(),
                'work_mode' => $log?->work_mode,
                'net_minutes' => $log?->net_minutes,
                'is_late' => $isLate,
                'break_start_at' => null,
                'break_end_at' => null,
            ];
        })->values()->all();

        $stats = $this->computeReportStats($rows, $roster->count());

        return response()->json([
            'date' => $date->toDateString(),
            'rows' => $rows,
            'stats' => $stats,
        ]);
    }

    /**
     * Monthly aggregates for scoped users (Mon–Fri business days + averages).
     */
    public function monthlySummary(Request $request)
    {
        $this->ensureAttendanceReportAccess($request);

        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'department' => ['nullable', 'in:all,PM,IM'],
        ]);

        $year = (int) $validated['year'];
        $month = (int) $validated['month'];
        $department = $validated['department'] ?? 'all';

        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, config('app.timezone'))->startOfDay();
        $end = $start->endOfMonth();

        $roster = $this->rosterUsersQuery($request, $department)->pluck('id');
        $rosterCount = $roster->count();

        $businessDays = 0;
        for ($d = $start->startOfDay(); $d->lte($end); $d = $d->addDay()) {
            if ($d->isWeekday()) {
                $businessDays++;
            }
        }

        $logs = AttendanceLog::query()
            ->whereIn('user_id', $roster)
            ->whereBetween('day_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotNull('check_in_at')
            ->get(['user_id', 'day_date', 'check_in_at', 'check_out_at', 'net_minutes']);

        $perUserDistinctDays = [];
        foreach ($logs as $log) {
            $key = $log->user_id;
            if (! isset($perUserDistinctDays[$key])) {
                $perUserDistinctDays[$key] = [];
            }
            $perUserDistinctDays[$key][$log->day_date->format('Y-m-d')] = true;
        }

        $sumDays = 0;
        foreach ($roster as $uid) {
            $sumDays += isset($perUserDistinctDays[$uid]) ? count($perUserDistinctDays[$uid]) : 0;
        }

        $workingDaysPresent = $rosterCount > 0 ? (int) round($sumDays / $rosterCount) : 0;

        $avgCheckIn = $this->averageClockMinutes($logs->pluck('check_in_at')->filter());
        $avgCheckOut = $this->averageClockMinutes($logs->pluck('check_out_at')->filter());
        $closed = $logs->whereNotNull('check_out_at')->whereNotNull('net_minutes');
        $avgHours = $closed->isEmpty() ? null : round($closed->avg('net_minutes') / 60, 1);

        return response()->json([
            'year' => $year,
            'month' => $month,
            'working_days_present' => $workingDaysPresent,
            'business_days_in_month' => $businessDays,
            'avg_check_in' => $avgCheckIn,
            'avg_check_out' => $avgCheckOut,
            'avg_hours_per_day' => $avgHours,
        ]);
    }

    private function ensureAttendanceReportAccess(Request $request): void
    {
        $user = $request->user()?->loadMissing('role');
        $key = $user?->role?->key;
        if (! in_array($key, ['super_admin', 'admin', 'dept_head'], true)) {
            abort(403, 'You are not authorized to view attendance reports.');
        }
    }

    private function rosterUsersQuery(Request $request, string $department): Builder
    {
        $actor = $request->user();

        $q = User::query()->where('status', 'active');

        if ($actor->role?->key === 'dept_head') {
            $code = $actor->department;
            if ($code) {
                $q->where('department', $code);
            }
        } else {
            if ($department === 'PM') {
                $q->where('department', 'PM');
            } elseif ($department === 'IM') {
                $q->where('department', 'IM');
            }
        }

        $needle = trim((string) $request->input('q', ''));
        if ($needle !== '') {
            $q->where(function (Builder $w) use ($needle) {
                $w->where('first_name', 'like', '%'.$needle.'%')
                    ->orWhere('last_name', 'like', '%'.$needle.'%')
                    ->orWhere('email', 'like', '%'.$needle.'%');
            });
        }

        return $q;
    }

    private function lateThresholdForDate(CarbonImmutable $date): CarbonImmutable
    {
        $time = (string) config('attendance.late_after', '09:10');

        return CarbonImmutable::parse($date->toDateString().' '.$time, config('app.timezone'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function computeReportStats(array $rows, int $rosterTotal): array
    {
        $checkedIn = 0;
        $checkedOut = 0;
        $wfh = 0;
        $office = 0;
        $late = 0;

        foreach ($rows as $r) {
            if ($r['check_in_at'] !== null) {
                $checkedIn++;
            }
            if ($r['check_out_at'] !== null) {
                $checkedOut++;
            }
            if (($r['work_mode'] ?? null) === 'WFH') {
                $wfh++;
            }
            if (($r['work_mode'] ?? null) === 'OFFICE') {
                $office++;
            }
            if (! empty($r['is_late'])) {
                $late++;
            }
        }

        return [
            'checked_in' => $checkedIn,
            'on_break' => 0,
            'checked_out' => $checkedOut,
            'not_checked_in' => max(0, $rosterTotal - $checkedIn),
            'wfh' => $wfh,
            'office' => $office,
            'late' => $late,
            'roster_total' => $rosterTotal,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \Carbon\Carbon>  $times
     */
    private function averageClockMinutes($times): ?string
    {
        if ($times->isEmpty()) {
            return null;
        }
        $sum = 0;
        $n = 0;
        foreach ($times as $t) {
            if (! $t instanceof Carbon) {
                continue;
            }
            $sum += $t->hour * 60 + $t->minute + $t->second / 60;
            $n++;
        }
        if ($n === 0) {
            return null;
        }
        $avg = $sum / $n;
        $h = (int) floor($avg / 60);
        $m = (int) round($avg - $h * 60);
        if ($m === 60) {
            $h++;
            $m = 0;
        }
        $carbon = Carbon::createFromTime($h, $m, 0, config('app.timezone'));

        return $carbon->format('h:i A');
    }
}
