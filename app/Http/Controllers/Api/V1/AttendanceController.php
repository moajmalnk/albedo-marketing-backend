<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\User;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use App\Models\AttendanceSetting;
use App\Models\Holiday;

class AttendanceController extends Controller
{
    public function checkIn(Request $request)
    {
        $data = $request->validate(['work_mode' => ['required', 'in:OFFICE,WFH']]);
        $userId = $request->user()->id;
        $today = now()->toDateString();

        if ($data['work_mode'] === 'WFH') {
            $hasApprovedWfh = \App\Models\WfhRequest::query()
                ->where('user_id', $userId)
                ->where('status', 'Approved')
                ->where('from_date', '<=', $today)
                ->where('to_date', '>=', $today)
                ->exists();

            if (! $hasApprovedWfh) {
                return response()->json(['message' => 'WFH_NOT_APPROVED'], 403);
            }
        }

        $openLog = $this->openLogForToday($userId);
        if ($openLog) {
            return response()->json($openLog, 200);
        }

        $latestLog = AttendanceLog::query()
            ->where('user_id', $userId)
            ->whereDate('day_date', $today)
            ->latest('session_number')
            ->first();

        if ($this->supportsAttendanceColumn('is_final_session') && $latestLog?->is_final_session) {
            return response()->json(['message' => 'DAY_ALREADY_FINALIZED'], 409);
        }

        $log = AttendanceLog::query()->create([
            'user_id' => $userId,
            'work_mode' => $data['work_mode'],
            'check_in_at' => now(),
            'day_date' => $today,
            'session_number' => ((int) ($latestLog?->session_number ?? 0)) + 1,
        ]);

        return response()->json($log, 201);
    }

    public function startBreak(Request $request)
    {
        $log = $this->openLogForToday($request->user()->id);
        if (! $log) {
            return response()->json(['message' => 'NO_OPEN_ATTENDANCE_SESSION'], 422);
        }

        if (! $this->supportsBreakTracking()) {
            return response()->json(['message' => 'BREAK_TRACKING_NOT_ENABLED'], 503);
        }

        if ($log->break_started_at) {
            return response()->json($log, 200);
        }

        $log->update(['break_started_at' => now()]);

        return response()->json($log->fresh());
    }

    public function endBreak(Request $request)
    {
        $log = $this->openLogForToday($request->user()->id);
        if (! $log) {
            return response()->json(['message' => 'NO_OPEN_ATTENDANCE_SESSION'], 422);
        }

        if (! $this->supportsBreakTracking()) {
            return response()->json(['message' => 'BREAK_TRACKING_NOT_ENABLED'], 503);
        }

        if (! $log->break_started_at) {
            return response()->json($log, 200);
        }

        $endedAt = now();
        $additionalSeconds = (int) max(0, $log->break_started_at->diffInSeconds($endedAt));
        $log->update([
            'break_started_at' => null,
            'break_last_ended_at' => $endedAt,
            'break_seconds' => ((int) $log->break_seconds) + $additionalSeconds,
        ]);

        return response()->json($log->fresh());
    }

    public function checkOut(Request $request)
    {
        $data = $request->validate([
            'finalize' => ['sometimes', 'boolean'],
            'summary.leads_handled' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'summary.calls_made' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'summary.conversions' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'summary.followups_completed' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'summary.notes' => ['nullable', 'string', 'max:1000'],
            'summary.issues' => ['nullable', 'string', 'max:1000'],
        ]);

        $log = $this->openLogForToday($request->user()->id);
        if (! $log) {
            return response()->json(['message' => 'NO_OPEN_ATTENDANCE_SESSION'], 422);
        }

        $checkedOutAt = now();
        $supportsBreakTracking = $this->supportsAttendanceColumn('break_seconds')
            && $this->supportsAttendanceColumn('break_started_at');
        $breakSeconds = $supportsBreakTracking ? (int) $log->break_seconds : 0;
        if ($supportsBreakTracking && $log->break_started_at) {
            $breakSeconds += (int) max(0, $log->break_started_at->diffInSeconds($checkedOutAt));
        }

        $summary = $data['summary'] ?? [];
        $updates = [
            'check_out_at' => $checkedOutAt,
            'net_minutes' => (int) max(0, $log->check_in_at->diffInMinutes($checkedOutAt) - ($breakSeconds / 60)),
        ];

        if ($supportsBreakTracking) {
            $updates['break_started_at'] = null;
            $updates['break_seconds'] = $breakSeconds;
        }
        if ($this->supportsAttendanceColumn('break_last_ended_at')) {
            $updates['break_last_ended_at'] = $log->break_started_at ? $checkedOutAt : $log->break_last_ended_at;
        }
        if ($this->supportsAttendanceColumn('is_final_session')) {
            $updates['is_final_session'] = (bool) ($data['finalize'] ?? true);
        }
        if ($this->supportsAttendanceColumn('summary_leads_handled')) {
            $updates['summary_leads_handled'] = $summary['leads_handled'] ?? null;
        }
        if ($this->supportsAttendanceColumn('summary_calls_made')) {
            $updates['summary_calls_made'] = $summary['calls_made'] ?? null;
        }
        if ($this->supportsAttendanceColumn('summary_conversions')) {
            $updates['summary_conversions'] = $summary['conversions'] ?? null;
        }
        if ($this->supportsAttendanceColumn('summary_followups_completed')) {
            $updates['summary_followups_completed'] = $summary['followups_completed'] ?? null;
        }
        if ($this->supportsAttendanceColumn('summary_notes')) {
            $updates['summary_notes'] = isset($summary['notes']) ? trim((string) $summary['notes']) : null;
        }
        if ($this->supportsAttendanceColumn('summary_issues')) {
            $updates['summary_issues'] = isset($summary['issues']) ? trim((string) $summary['issues']) : null;
        }

        $log->update($updates);

        return response()->json($log->fresh());
    }

    public function pendingCorrections(Request $request)
    {
        $log = AttendanceLog::query()
            ->where('user_id', $request->user()->id)
            ->where('auto_closed', true)
            ->orderBy('day_date', 'asc')
            ->first();

        return response()->json($log);
    }

    public function correctLog(Request $request)
    {
        $data = $request->validate([
            'log_id' => ['required', 'exists:attendance_logs,id'],
            'checkout_time' => ['required', 'date_format:H:i'],
            'summary.leads_handled' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'summary.calls_made' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'summary.conversions' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'summary.followups_completed' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'summary.notes' => ['nullable', 'string', 'max:1000'],
            'summary.issues' => ['nullable', 'string', 'max:1000'],
        ]);

        $log = AttendanceLog::where('id', $data['log_id'])
            ->where('user_id', $request->user()->id)
            ->where('auto_closed', true)
            ->firstOrFail();

        // The checkout time is capped to 19:00 (7:00 PM) based on standard shift end.
        $checkoutTimeStr = $data['checkout_time'];
        // Parse date properly to handle CarbonImmutable
        $dateStr = $log->day_date instanceof \DateTimeInterface ? $log->day_date->format('Y-m-d') : $log->day_date;
        $checkoutCarbon = Carbon::parse($dateStr . ' ' . $checkoutTimeStr);
        $capTime = Carbon::parse($dateStr . ' 19:00:00');

        if ($checkoutCarbon->gt($capTime)) {
            $checkoutCarbon = $capTime;
        }

        if ($checkoutCarbon->lt($log->check_in_at)) {
            $checkoutCarbon = $log->check_in_at;
        }

        $checkedOutAt = $checkoutCarbon;
        $breakSeconds = (int) $log->break_seconds;

        $summary = $data['summary'] ?? [];
        $updates = [
            'check_out_at' => $checkedOutAt,
            'net_minutes' => (int) max(0, $log->check_in_at->diffInMinutes($checkedOutAt) - ($breakSeconds / 60)),
            'auto_closed' => false, // Mark it as resolved
        ];

        if ($this->supportsAttendanceColumn('summary_leads_handled')) {
            $updates['summary_leads_handled'] = $summary['leads_handled'] ?? null;
        }
        if ($this->supportsAttendanceColumn('summary_calls_made')) {
            $updates['summary_calls_made'] = $summary['calls_made'] ?? null;
        }
        if ($this->supportsAttendanceColumn('summary_conversions')) {
            $updates['summary_conversions'] = $summary['conversions'] ?? null;
        }
        if ($this->supportsAttendanceColumn('summary_followups_completed')) {
            $updates['summary_followups_completed'] = $summary['followups_completed'] ?? null;
        }
        if ($this->supportsAttendanceColumn('summary_notes')) {
            $updates['summary_notes'] = isset($summary['notes']) ? trim((string) $summary['notes']) : null;
        }
        if ($this->supportsAttendanceColumn('summary_issues')) {
            $updates['summary_issues'] = isset($summary['issues']) ? trim((string) $summary['issues']) : null;
        }

        $log->update($updates);

        return response()->json($log->fresh());
    }

    public function index(Request $request)
    {
        $this->ensureAttendanceReportAccess($request);

        $query = AttendanceLog::query()->with('user');

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->has('date')) {
            $query->whereDate('day_date', $request->date);
        }

        return response()->json($query->orderBy('day_date', 'desc')->paginate(15));
    }

    public function store(Request $request)
    {
        $this->ensureAttendanceReportAccess($request);
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'work_mode' => 'required|in:OFFICE,WFH',
            'check_in_at' => 'required|date',
            'check_out_at' => 'nullable|date|after_or_equal:check_in_at',
            'day_date' => 'required|date',
        ]);

        if (isset($validated['check_out_at']) && $validated['check_out_at']) {
            $checkIn = Carbon::parse($validated['check_in_at']);
            $checkOut = Carbon::parse($validated['check_out_at']);
            $validated['net_minutes'] = $checkIn->diffInMinutes($checkOut);
            $validated['is_final_session'] = true;
        }

        $validated['session_number'] = 1;
        $log = AttendanceLog::create($validated);
        return response()->json($log, 201);
    }

    public function update(Request $request, AttendanceLog $attendance)
    {
        $this->ensureAttendanceReportAccess($request);
        $validated = $request->validate([
            'work_mode' => 'required|in:OFFICE,WFH',
            'check_in_at' => 'required|date',
            'check_out_at' => 'nullable|date|after_or_equal:check_in_at',
            'day_date' => 'required|date',
        ]);

        if (isset($validated['check_out_at']) && $validated['check_out_at']) {
            $checkIn = Carbon::parse($validated['check_in_at']);
            $checkOut = Carbon::parse($validated['check_out_at']);
            $validated['net_minutes'] = $checkIn->diffInMinutes($checkOut);
        }

        $attendance->update($validated);
        return response()->json($attendance);
    }

    public function destroy(Request $request, AttendanceLog $attendance)
    {
        $this->ensureAttendanceReportAccess($request);
        $attendance->delete();
        return response()->json(null, 204);
    }

    public function today(Request $request)
    {
        $today = AttendanceLog::query()
            ->where('user_id', $request->user()->id)
            ->whereDate('day_date', now()->toDateString())
            ->orderBy('session_number')
            ->orderBy('check_in_at')
            ->get();
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

        $rosterQuery = $this->rosterUsersQuery($request, $department)->orderBy('first_name')->orderBy('last_name');
        
        $rosterCount = $rosterQuery->count();
        $paginator = $rosterQuery->paginate(15);

        $logsByUserId = AttendanceLog::query()
            ->whereDate('day_date', $date->toDateString())
            ->whereIn('user_id', $paginator->pluck('id'))
            ->orderBy('check_in_at')
            ->get()
            ->groupBy('user_id');

        $lateThreshold = $this->lateThresholdForDate($date);

        $usersOnLeave = LeaveRequest::query()
            ->where('status', 'Approved')
            ->where('from_date', '<=', $date->toDateString())
            ->where('to_date', '>=', $date->toDateString())
            ->whereIn('user_id', $paginator->pluck('id'))
            ->pluck('user_id');

        $rows = collect($paginator->items())->map(function (User $user) use ($logsByUserId, $lateThreshold, $usersOnLeave) {
            $logs = $logsByUserId->get($user->id, collect());
            $firstLog = $logs->first();
            $latestLog = $logs->sortByDesc('check_in_at')->first();
            $openLog = $logs->first(fn (AttendanceLog $log) => $log->check_out_at === null);
            $latestClosedLog = $logs
                ->filter(fn (AttendanceLog $log) => $log->check_out_at !== null)
                ->sortByDesc('check_out_at')
                ->first();
            $isLate = $firstLog ? $firstLog->check_in_at->gt($lateThreshold) : false;
            $netMinutes = $logs->sum(fn (AttendanceLog $log) => (int) ($log->net_minutes ?? 0));

            $breakSeconds = $logs->sum(fn (AttendanceLog $log) => (int) ($log->break_seconds ?? 0));
            $onBreak = $openLog && $openLog->break_started_at !== null;
            if ($onBreak) {
                $breakSeconds += (int) max(0, $openLog->break_started_at->diffInSeconds(now()));
            }
            $breakMinutes = $breakSeconds / 60;

            return [
                'user_id' => $user->id,
                'name' => trim($user->first_name.' '.($user->last_name ?? '')),
                'department' => $user->department ?? '',
                'check_in_at' => $firstLog?->check_in_at?->toIso8601String(),
                'check_out_at' => $openLog ? null : $latestClosedLog?->check_out_at?->toIso8601String(),
                'work_mode' => $latestLog?->work_mode,
                'net_minutes' => $netMinutes > 0 ? $netMinutes : null,
                'is_late' => $isLate,
                'break_minutes' => $breakMinutes > 0 ? $breakMinutes : null,
                'on_break' => $onBreak,
                'is_on_leave' => $usersOnLeave->contains($user->id),
            ];
        })->values()->all();

        // Calculate global stats for all users (not just paginated)
        $allRosterIds = $this->rosterUsersQuery($request, $department)->pluck('id');
        $allLogs = AttendanceLog::query()
            ->whereDate('day_date', $date->toDateString())
            ->whereIn('user_id', $allRosterIds)
            ->get(['user_id', 'work_mode', 'check_in_at', 'check_out_at']);
        
        $allLeaves = LeaveRequest::query()
            ->where('status', 'Approved')
            ->where('from_date', '<=', $date->toDateString())
            ->where('to_date', '>=', $date->toDateString())
            ->whereIn('user_id', $allRosterIds)
            ->pluck('user_id');

        $statsRaw = [];
        foreach ($allLogs->groupBy('user_id') as $userId => $userLogs) {
            $statsRaw[] = [
                'check_in_at' => $userLogs->min('check_in_at'),
                'check_out_at' => $userLogs->max('check_out_at'),
                'work_mode' => $userLogs->last()->work_mode,
                'is_late' => $userLogs->first()->check_in_at?->gt($lateThreshold) ?? false,
            ];
        }
        foreach ($allLeaves as $leaveUserId) {
            if (!isset($allLogs[$leaveUserId])) {
                $statsRaw[] = ['is_on_leave' => true, 'check_in_at' => null, 'check_out_at' => null];
            }
        }
        $stats = $this->computeReportStats($statsRaw, $rosterCount);

        return response()->json([
            'date' => $date->toDateString(),
            'rows' => $rows,
            'stats' => $stats,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ]
        ]);
    }

    public function summaries(Request $request)
    {
        $this->ensureAttendanceReportAccess($request);

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $query = AttendanceLog::query()
            ->with('user')
            ->where(function ($q) {
                $q->whereNotNull('summary_leads_handled')
                  ->orWhereNotNull('summary_calls_made')
                  ->orWhereNotNull('summary_conversions')
                  ->orWhereNotNull('summary_followups_completed')
                  ->orWhereNotNull('summary_notes')
                  ->orWhereNotNull('summary_issues');
            });

        if (isset($validated['from'])) {
            $query->whereDate('day_date', '>=', $validated['from']);
        }
        if (isset($validated['to'])) {
            $query->whereDate('day_date', '<=', $validated['to']);
        }

        $logs = $query->orderBy('day_date', 'desc')->get();

        return response()->json($logs->map(function ($log) {
            return [
                'userId' => (string) $log->user_id,
                'date' => $log->day_date instanceof \DateTimeInterface ? $log->day_date->format('Y-m-d') : (string) $log->day_date,
                'leadsHandled' => (int) ($log->summary_leads_handled ?? 0),
                'callsMade' => (int) ($log->summary_calls_made ?? 0),
                'conversions' => (int) ($log->summary_conversions ?? 0),
                'followUpsCompleted' => (int) ($log->summary_followups_completed ?? 0),
                'notes' => (string) ($log->summary_notes ?? ''),
                'issues' => (string) ($log->summary_issues ?? ''),
                'finalized' => (bool) ($log->is_final_session ?? true),
                'submittedAt' => $log->check_out_at ? $log->check_out_at->toIso8601String() : ($log->updated_at ? $log->updated_at->toIso8601String() : null),
            ];
        }));
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

        $setting = AttendanceSetting::first();
        $weekends = $setting ? (array) $setting->weekend_days : ['Saturday', 'Sunday'];
        $holidays = Holiday::whereBetween('date', [$start->toDateString(), $end->toDateString()])->pluck('date')->map(fn($d) => $d->toDateString())->toArray();

        $businessDays = 0;
        for ($d = $start->startOfDay(); $d->lte($end); $d = $d->addDay()) {
            if (!in_array($d->format('l'), $weekends) && !in_array($d->toDateString(), $holidays)) {
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

        $q = User::query()
            ->where('status', 'active')
            ->whereHas('role', function ($query) {
                $query->whereIn('key', ['telecaller', 'psa', 'marketer', 'advisor']);
            });

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
        $setting = AttendanceSetting::first();
        $time = $setting ? $setting->office_start_time : '09:00:00';
        $grace = $setting ? $setting->grace_period_minutes : 15;

        $threshold = CarbonImmutable::parse($date->toDateString().' '.$time, config('app.timezone'));
        return $threshold->addMinutes($grace);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function computeReportStats(array $rows, int $rosterTotal): array
    {
        $checkedIn = 0;
        $checkedOut = 0;
        $onBreak = 0;
        $wfh = 0;
        $office = 0;
        $late = 0;
        $onLeave = 0;

        foreach ($rows as $r) {
            if (! empty($r['is_on_leave'])) {
                $onLeave++;
            }
            if ($r['check_in_at'] !== null) {
                $checkedIn++;
            }
            if ($r['check_out_at'] !== null) {
                $checkedOut++;
            }
            if (! empty($r['on_break'])) {
                $onBreak++;
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
            'on_break' => $onBreak,
            'checked_out' => $checkedOut,
            'on_leave' => $onLeave,
            'not_checked_in' => max(0, $rosterTotal - $checkedIn - $onLeave),
            'wfh' => $wfh,
            'office' => $office,
            'late' => $late,
            'roster_total' => $rosterTotal,
        ];
    }

    private function openLogForToday(int $userId): ?AttendanceLog
    {
        return AttendanceLog::query()
            ->where('user_id', $userId)
            ->whereDate('day_date', now()->toDateString())
            ->whereNull('check_out_at')
            ->latest('session_number')
            ->first();
    }

    private function supportsAttendanceColumn(string $column): bool
    {
        static $columns = null;

        if ($columns === null) {
            $columns = array_flip(Schema::getColumnListing('attendance_logs'));
        }

        return isset($columns[$column]);
    }

    private function supportsBreakTracking(): bool
    {
        return $this->supportsAttendanceColumn('break_started_at')
            && $this->supportsAttendanceColumn('break_last_ended_at')
            && $this->supportsAttendanceColumn('break_seconds');
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
