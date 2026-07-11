<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Lead;
use App\Models\User;
use App\Support\LeadChannelClassifier;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class MarketingAnalyticsService
{
    /**
     * Build filtered lead query from dashboard filter params (Admin / Super Admin / Dept head).
     *
     * @param  ?string  $tabOverride  When set, replaces request "tab" (e.g. "All" for MTD goal ignoring channel tab).
     */
    public function filteredLeadQuery(Request $request, ?string $tabOverride = null): Builder
    {
        $user = $request->user();
        $user?->loadMissing('role');

        $query = Lead::query();

        $tab = $tabOverride ?? $request->input('tab', 'All');
        if (in_array($tab, ['WhatsApp', 'Form', 'Call', 'Message'], true)) {
            LeadChannelClassifier::applyChannelFilter($query, $tab);
        }

        $month = $request->input('month');
        if ($month !== null && $month !== '' && $month !== 'All') {
            $m = (int) $month;
            if ($m >= 1 && $m <= 12) {
                $query->whereMonth('leads.created_at', $m);
            }
        }

        $year = $request->input('year');
        if ($year !== null && $year !== '' && $year !== 'All') {
            $y = (int) $year;
            if ($y >= 2000 && $y <= 2100) {
                $query->whereYear('leads.created_at', $y);
            }
        }

        $location = $request->input('location');
        if ($location === 'Kerala') {
            $query->where(function (Builder $q) {
                $q->where('country', 'India')->orWhereNull('country');
            });
        } elseif ($location === 'Gulf') {
            $query->where(function (Builder $q) {
                $q->where('country', '<>', 'India')->whereNotNull('country');
            });
        }

        $platform = $request->input('platform');
        if ($platform === 'Meta') {
            $query->where(function (Builder $q) {
                $q->whereRaw('LOWER(COALESCE(campaign, \'\')) LIKE ?', ['%meta%'])
                    ->orWhereRaw('LOWER(COALESCE(source_code, \'\')) LIKE ?', ['%meta%']);
            });
        } elseif ($platform === 'Google') {
            $query->where(function (Builder $q) {
                $q->whereRaw('LOWER(COALESCE(campaign, \'\')) LIKE ?', ['%google%'])
                    ->orWhereRaw('LOWER(COALESCE(source_code, \'\')) LIKE ?', ['%google%']);
            });
        } elseif ($platform === 'Website') {
            $query->where(function (Builder $q) {
                $q->whereNull('campaign')->where(function (Builder $q2) {
                    $q2->whereNull('source_code')->orWhere('source_code', 'import');
                });
            });
        }

        $department = $request->input('department');
        if ($department === 'Performance Marketing') {
            $query->where('source_group', 'performance');
        } elseif ($department === 'Influence Marketing') {
            $query->where('source_group', 'influence');
        }

        $assignedDept = $request->input('assigned_dept');
        if (in_array($assignedDept, ['SALES', 'MARKETING'], true)) {
            $query->where('assigned_dept', $assignedDept);
        }

        $statusFilter = $request->input('status_filter', 'All');
        if ($statusFilter === 'Qualified') {
            $query->whereHas('stage', fn (Builder $q) => $q->whereIn('key', config('marketing.qualified_stage_keys', ['qualified', 'sales_head_review', 'advisor_counselling', 'returned_to_advisor', 'enrolled'])));
        } elseif ($statusFilter === 'Follow-up') {
            $query->whereHas('stage', fn (Builder $q) => $q->whereIn('key', ['new_lead', 'assigned_telecaller', 'recovery_required', 'psa_recovery']));
        } elseif ($statusFilter === 'Not Interested') {
            $query->whereHas('closedReason', fn (Builder $q) => $q->whereIn('key', ['budget_issue', 'joined_competitor', 'not_interested', 'no_response', 'parent_rejected']));
        } elseif ($statusFilter === 'Fraud') {
            $query->whereHas('closedReason', fn (Builder $q) => $q->whereIn('key', ['wrong_number', 'duplicate', 'fake_lead']));
        }

        $marketingTeam = $request->input('marketing_team');
        if ($marketingTeam === 'performance') {
            $query->where('source_group', 'performance');
        } elseif ($marketingTeam === 'influence') {
            $query->where('source_group', 'influence');
        }

        $user = $request->user();
        if ($user && $user->relationLoaded('role') === false) {
            $user->load('role');
        }
        if (in_array($user?->role?->key, ['dept_head', 'department_head'], true) && ! $request->filled('marketing_team')) {
            $dept = $user->department;
            if ($dept === 'PM') {
                $query->where('source_group', 'performance');
            } elseif ($dept === 'IM') {
                $query->where('source_group', 'influence');
            }
        }

        return $query;
    }



    /**
     * @return array<string, mixed>
     */
    public function summarize(Request $request): array
    {
        $base = $this->filteredLeadQuery($request);
        $qualifiedKeys = config('marketing.qualified_stage_keys', ['enrolled', 'itb']);

        $channelSql = "CASE
            WHEN whatsapp_id IS NOT NULL OR LOWER(COALESCE(source_code, '')) LIKE '%whatsapp%' OR source_code = 'whatsapp' THEN 'WhatsApp'
            WHEN LOWER(COALESCE(connected_by, '')) LIKE '%call%' OR LOWER(COALESCE(source_code, '')) LIKE '%call%' THEN 'Call'
            WHEN LOWER(COALESCE(source_code, '')) LIKE '%message%' OR LOWER(COALESCE(source_code, '')) LIKE '%sms%' OR LOWER(COALESCE(connected_by, '')) LIKE '%message%' THEN 'Message'
            ELSE 'Form'
        END";

        $baseStats = (clone $base)
            ->leftJoin('lead_stages', 'leads.stage_id', '=', 'lead_stages.id')
            ->selectRaw("
                COUNT(leads.id) as total_leads,
                SUM(CASE WHEN ($channelSql) = 'WhatsApp' THEN 1 ELSE 0 END) as wa,
                SUM(CASE WHEN ($channelSql) = 'Form' THEN 1 ELSE 0 END) as form,
                SUM(CASE WHEN ($channelSql) = 'Call' THEN 1 ELSE 0 END) as call_count,
                SUM(CASE WHEN ($channelSql) = 'Message' THEN 1 ELSE 0 END) as msg,
                SUM(CASE WHEN lead_stages.key IN ('" . implode("','", $qualifiedKeys) . "') THEN 1 ELSE 0 END) as qualified_leads,
                SUM(CASE WHEN lead_stages.key = 'enrolled' THEN 1 ELSE 0 END) as admissions_leads,
                SUM(CASE WHEN lead_stages.key IN ('follow_up', 'prospect', 'demo_required', 'dnp') THEN 1 ELSE 0 END) as followup_leads
            ")
            ->first();

        $totalLeads = (int) ($baseStats->total_leads ?? 0);
        $whatsapp = (int) ($baseStats->wa ?? 0);
        $form = (int) ($baseStats->form ?? 0);
        $call = (int) ($baseStats->call_count ?? 0);
        $message = (int) ($baseStats->msg ?? 0);
        $qualifiedLeads = (int) ($baseStats->qualified_leads ?? 0);
        $admissionsLeads = (int) ($baseStats->admissions_leads ?? 0);
        $followUpLeads = (int) ($baseStats->followup_leads ?? 0);

        $conversionPct = $totalLeads > 0 ? (int) round(($qualifiedLeads / $totalLeads) * 100) : 0;

        $target = (int) config('marketing.monthly_lead_target', 500);
        $monthStart = now()->startOfMonth();
        $mtdLeads = (int) $this->filteredLeadQuery($request, 'All')
            ->where('created_at', '>=', $monthStart)
            ->count();
        $goalPct = $target > 0 ? (int) min(100, round(($mtdLeads / $target) * 100)) : 0;

        $prevMonthStart = (clone $monthStart)->subMonth();
        $prevMtdEnd = $prevMonthStart->copy()->addDays($monthStart->diffInDays(now()));
        $prevMtdLeads = (int) $this->filteredLeadQuery($request, 'All')
            ->whereBetween('created_at', [$prevMonthStart, $prevMtdEnd])
            ->count();
        $momLeadsPct = $this->percentChange($mtdLeads, $prevMtdLeads);

        $weekly = $this->weeklySeries(clone $base, $qualifiedKeys);

        // Find today's stats from the weekly series (today is the last element, i.e., index 6)
        $todayStats = $weekly[6] ?? ['leads' => 0, 'qualified' => 0];
        $todayLeads = (int) $todayStats['leads'];
        $todayQualified = (int) $todayStats['qualified'];
        $todayRate = $todayLeads > 0 ? (int) round(($todayQualified / $todayLeads) * 100) : 0;

        $trends = $this->channelMonthOverMonth();

        $checkedInUserIds = AttendanceLog::query()
            ->whereDate('day_date', now()->toDateString())
            ->whereNull('check_out_at')
            ->distinct()
            ->pluck('user_id')
            ->toArray();

        $checkedInToday = count($checkedInUserIds);
        $totalActiveStaff = User::query()->where('status', 'active')->count();

        $totalActiveWorkers = User::query()
            ->where('status', 'active')
            ->whereHas('role', fn ($q) => $q->whereIn('key', ['telecaller', 'psa', 'marketer', 'advisor']))
            ->count();

        $activeLeadersCount = User::query()
            ->where('status', 'active')
            ->whereHas('role', fn ($q) => $q->whereNotIn('key', ['telecaller', 'psa', 'marketer', 'advisor']))
            ->where('last_login_at', '>=', now()->startOfDay())
            ->count();

        $activeUsers = $activeLeadersCount + count($checkedInUserIds);

        $attendanceToday = AttendanceLog::query()
            ->whereDate('day_date', now()->toDateString())
            ->with(['user' => fn ($q) => $q->select('id', 'first_name', 'last_name', 'department')])
            ->orderByDesc('check_in_at')
            ->limit(40)
            ->get()
            ->map(function ($log) {
                $u = $log->user;

                return [
                    'id' => $log->id,
                    'user_name' => $u ? trim($u->first_name.' '.($u->last_name ?? '')) : '',
                    'department' => $u?->department,
                    'check_in' => $log->check_in_at?->format('H:i'),
                    'check_out' => $log->check_out_at?->format('H:i'),
                    'work_mode' => $log->work_mode,
                    'net_minutes' => $log->net_minutes,
                ];
            });

        $staffPreview = User::query()
            ->where('status', 'active')
            ->with(['role:id,key,name'])
            ->orderByDesc('last_login_at')
            ->limit(40)
            ->get()
            ->map(function (User $u) use ($checkedInUserIds) {
                $roleKey = $u->role?->key;

                if (in_array($roleKey, ['telecaller', 'psa', 'marketer', 'advisor'], true)) {
                    $isOnline = in_array($u->id, $checkedInUserIds, true);
                } else {
                    $isOnline = $u->last_login_at && $u->last_login_at->isToday();
                }

                return [
                    'id' => $u->id,
                    'name' => trim($u->first_name.' '.($u->last_name ?? '')),
                    'role_key' => $roleKey,
                    'last_login_at' => $u->last_login_at?->toIso8601String(),
                    'online_last_24h' => $isOnline,
                ];
            });

        $recentLeads = (clone $base)->with(['stage', 'owner'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (Lead $l) => [
                'id' => $l->id,
                'student_name' => $l->student_name,
                'phone' => $l->phone,
                'status_label' => $l->stage?->label ?? $l->status,
                'source' => $l->source_code ?? '',
                'assigned_to' => $l->owner
                    ? trim($l->owner->first_name.' '.($l->owner->last_name ?? ''))
                    : null,
                'date' => $l->created_at?->toDateString(),
            ]);

        $sourceBreakdown = $this->sourceBreakdown(clone $base);
        $departmentComparison = $this->departmentComparisonFromBase(clone $base, $qualifiedKeys);
        $telecallerPerformance = $this->telecallerPerformance(clone $base, $qualifiedKeys);

        $unassignedLeadsCount = (clone $base)->whereNull('owner_id')->count();
        $overdueLeadsCount = (clone $base)
            ->whereNotNull('owner_id')
            ->where('created_at', '<', now()->subHours(24))
            ->whereHas('stage', fn (Builder $q) => $q->whereIn('key', ['new_lead', 'assigned_telecaller', 'recovery_required', 'psa_recovery']))
            ->count();

        return [
            'channels' => [
                'whatsapp' => $whatsapp,
                'form' => $form,
                'call' => $call,
                'message' => $message,
            ],
            'channel_trends_percent' => $trends,
            'totals' => [
                'total_leads' => $totalLeads,
                'qualified_leads' => $qualifiedLeads,
                'admissions_count' => $admissionsLeads,
                'follow_up_leads' => $followUpLeads,
                'conversion_percent' => $conversionPct,
            ],
            'goal' => [
                'percentage' => $goalPct,
                'month_to_date_leads' => $mtdLeads,
                'monthly_target' => $target,
                'month_over_month_leads_percent' => $momLeadsPct,
            ],
            'weekly_lead_intake' => $weekly,
            'conversion_trend' => collect($weekly)->map(fn (array $d) => [
                'day' => $d['day'],
                'rate' => $d['leads'] > 0 ? (int) round(($d['qualified'] / $d['leads']) * 100) : 0,
            ])->all(),
            'conversion_today_percent' => $todayRate,
            'attendance' => [
                'checked_in_today' => $checkedInToday,
                'active_users_denominator' => $totalActiveWorkers,
            ],
            'active_users' => [
                'logged_in_last_24h' => $activeUsers,
                'total_active_staff' => $totalActiveStaff,
            ],
            'recent_leads' => $recentLeads,
            'lead_source_breakdown' => $sourceBreakdown,
            'department_comparison' => $departmentComparison,
            'telecaller_performance' => $telecallerPerformance,
            'attendance_today_rows' => $attendanceToday,
            'staff_preview' => $staffPreview,
            'health_metrics' => [
                'unassigned_leads' => $unassignedLeadsCount,
                'overdue_leads' => $overdueLeadsCount,
            ],
        ];
    }

    /**
     * @param  list<string>  $qualifiedKeys
     * @return list<array{day: string, leads: int, qualified: int}>
     */
    private function weeklySeries(Builder $base, array $qualifiedKeys): array
    {
        $startDate = Carbon::today()->subDays(6)->startOfDay();
        $endDate = Carbon::today()->endOfDay();

        $weeklyStats = (clone $base)
            ->leftJoin('lead_stages', 'leads.stage_id', '=', 'lead_stages.id')
            ->whereBetween('leads.created_at', [$startDate, $endDate])
            ->selectRaw('DATE(leads.created_at) as date')
            ->selectRaw('COUNT(leads.id) as total_leads')
            ->selectRaw("SUM(CASE WHEN lead_stages.key IN ('" . implode("','", $qualifiedKeys) . "') THEN 1 ELSE 0 END) as qualified_leads")
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        $out = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = Carbon::today()->subDays($i);
            $dayStr = $day->toDateString();
            $row = $weeklyStats[$dayStr] ?? null;
            $out[] = [
                'day' => $day->format('D'),
                'leads' => (int) ($row?->total_leads ?? 0),
                'qualified' => (int) ($row?->qualified_leads ?? 0),
            ];
        }

        return $out;
    }

    private function percentChange(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return $current > 0 ? 100.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * @return array{whatsapp: ?float, form: ?float, call: ?float, message: ?float, total_leads: ?float}
     */
    private function channelMonthOverMonth(): array
    {
        $startThis = now()->startOfMonth();
        $startPrev = (clone $startThis)->subMonth();
        $endPrev = (clone $startThis)->subSecond();
        $thisMonthEnd = now();

        $channelSql = "CASE
            WHEN whatsapp_id IS NOT NULL OR LOWER(COALESCE(source_code, '')) LIKE '%whatsapp%' OR source_code = 'whatsapp' THEN 'WhatsApp'
            WHEN LOWER(COALESCE(connected_by, '')) LIKE '%call%' OR LOWER(COALESCE(source_code, '')) LIKE '%call%' THEN 'Call'
            WHEN LOWER(COALESCE(source_code, '')) LIKE '%message%' OR LOWER(COALESCE(source_code, '')) LIKE '%sms%' OR LOWER(COALESCE(connected_by, '')) LIKE '%message%' THEN 'Message'
            ELSE 'Form'
        END";

        $channelCounts = Lead::query()
            ->whereBetween('created_at', [$startPrev, $thisMonthEnd])
            ->selectRaw("
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as this_month_total,
                SUM(CASE WHEN created_at < ? THEN 1 ELSE 0 END) as prev_month_total,
                SUM(CASE WHEN ($channelSql) = 'WhatsApp' AND created_at >= ? THEN 1 ELSE 0 END) as this_wa,
                SUM(CASE WHEN ($channelSql) = 'WhatsApp' AND created_at < ? THEN 1 ELSE 0 END) as prev_wa,
                SUM(CASE WHEN ($channelSql) = 'Form' AND created_at >= ? THEN 1 ELSE 0 END) as this_form,
                SUM(CASE WHEN ($channelSql) = 'Form' AND created_at < ? THEN 1 ELSE 0 END) as prev_form,
                SUM(CASE WHEN ($channelSql) = 'Call' AND created_at >= ? THEN 1 ELSE 0 END) as this_call,
                SUM(CASE WHEN ($channelSql) = 'Call' AND created_at < ? THEN 1 ELSE 0 END) as prev_call,
                SUM(CASE WHEN ($channelSql) = 'Message' AND created_at >= ? THEN 1 ELSE 0 END) as this_msg,
                SUM(CASE WHEN ($channelSql) = 'Message' AND created_at < ? THEN 1 ELSE 0 END) as prev_msg
            ", [
                $startThis, $startThis,
                $startThis, $startThis,
                $startThis, $startThis,
                $startThis, $startThis,
                $startThis, $startThis,
            ])
            ->first();

        $waT = (int) ($channelCounts->this_wa ?? 0);
        $waP = (int) ($channelCounts->prev_wa ?? 0);
        $formT = (int) ($channelCounts->this_form ?? 0);
        $formP = (int) ($channelCounts->prev_form ?? 0);
        $callT = (int) ($channelCounts->this_call ?? 0);
        $callP = (int) ($channelCounts->prev_call ?? 0);
        $msgT = (int) ($channelCounts->this_msg ?? 0);
        $msgP = (int) ($channelCounts->prev_msg ?? 0);
        $totalT = (int) ($channelCounts->this_month_total ?? 0);
        $totalP = (int) ($channelCounts->prev_month_total ?? 0);

        return [
            'whatsapp' => $this->percentChange($waT, $waP),
            'form' => $this->percentChange($formT, $formP),
            'call' => $this->percentChange($callT, $callP),
            'message' => $this->percentChange($msgT, $msgP),
            'total_leads' => $this->percentChange($totalT, $totalP),
        ];
    }

    /**
     * @return list<array{source: string, leads: int, qualified: int, rate: int}>
     */
    private function sourceBreakdown(Builder $base): array
    {
        $qualifiedKeys = config('marketing.qualified_stage_keys', ['enrolled', 'itb']);
        $rows = (clone $base)
            ->selectRaw('COALESCE(NULLIF(TRIM(source_code), \'\'), \'unknown\') as src')
            ->selectRaw('COUNT(*) as c')
            ->groupBy('src')
            ->orderByDesc('c')
            ->limit(12)
            ->get();

        $qualifiedQuery = (clone $base)
            ->selectRaw('COALESCE(NULLIF(TRIM(source_code), \'\'), \'unknown\') as src')
            ->selectRaw('COUNT(*) as count')
            ->whereHas('stage', fn (Builder $q) => $q->whereIn('key', $qualifiedKeys))
            ->groupBy('src')
            ->pluck('count', 'src');

        $out = [];
        foreach ($rows as $row) {
            $src = (string) $row->src;
            $leads = (int) $row->c;
            $qualified = (int) ($qualifiedQuery[$src] ?? 0);
            $rate = $leads > 0 ? (int) round(($qualified / $leads) * 100) : 0;
            $out[] = [
                'source' => $src,
                'leads' => $leads,
                'qualified' => $qualified,
                'rate' => $rate,
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>  $qualifiedKeys
     * @return array{pm: array<string, mixed>, im: array<string, mixed>, totals: array<string, mixed>}
     */
    private function departmentComparisonFromBase(Builder $base, array $qualifiedKeys): array
    {
        $deptStats = (clone $base)
            ->leftJoin('lead_stages', 'leads.stage_id', '=', 'lead_stages.id')
            ->leftJoin('lead_closed_reasons', 'leads.closed_reason_id', '=', 'lead_closed_reasons.id')
            ->selectRaw('leads.source_group')
            ->selectRaw('COUNT(leads.id) as total')
            ->selectRaw("SUM(CASE WHEN lead_stages.key IN ('" . implode("','", $qualifiedKeys) . "') THEN 1 ELSE 0 END) as qualified")
            ->selectRaw("SUM(CASE WHEN lead_closed_reasons.key IN ('budget_issue', 'joined_competitor', 'not_interested', 'no_response', 'parent_rejected') THEN 1 ELSE 0 END) as not_interested")
            ->selectRaw("SUM(CASE WHEN lead_stages.key IN ('new_lead', 'assigned_telecaller', 'recovery_required', 'psa_recovery') THEN 1 ELSE 0 END) as follow_ups")
            ->groupBy('leads.source_group')
            ->get()
            ->keyBy('source_group');

        $getBucket = function (string $group) use ($deptStats): array {
            $row = $deptStats[$group] ?? null;
            $total = (int) ($row?->total ?? 0);
            $qual = (int) ($row?->qualified ?? 0);
            $ni = (int) ($row?->not_interested ?? 0);
            $fu = (int) ($row?->follow_ups ?? 0);
            $conv = $total > 0 ? (int) round(($qual / $total) * 100) : 0;

            return [
                'total_leads' => $total,
                'qualified' => $qual,
                'not_interested' => $ni,
                'follow_ups' => $fu,
                'conversion_percent' => $conv,
            ];
        };

        $pm = $getBucket('performance');
        $im = $getBucket('influence');

        return [
            'pm' => $pm,
            'im' => $im,
            'totals' => [
                'total_leads' => $pm['total_leads'] + $im['total_leads'],
                'qualified' => $pm['qualified'] + $im['qualified'],
                'not_interested' => $pm['not_interested'] + $im['not_interested'],
                'follow_ups' => $pm['follow_ups'] + $im['follow_ups'],
                'conversion_percent' => ($pm['total_leads'] + $im['total_leads']) > 0
                    ? (int) round((($pm['qualified'] + $im['qualified']) / ($pm['total_leads'] + $im['total_leads'])) * 100)
                    : 0,
            ],
        ];
    }

    /**
     * @param  list<string>  $qualifiedKeys
     * @return list<array{id: int, name: string, dept: string, assigned: int, contacted: int, qualified: int, follow_up: int, conversion: int, pending_24h: int}>
     */
    private function telecallerPerformance(Builder $base, array $qualifiedKeys): array
    {
        $telecallerIds = User::query()
            ->whereHas('role', fn (Builder $q) => $q->where('key', 'telecaller'))
            ->pluck('id');

        if ($telecallerIds->isEmpty()) {
            return [];
        }

        $baseWithOwners = (clone $base)->whereIn('owner_id', $telecallerIds);

        $assignedQuery = (clone $baseWithOwners)
            ->groupBy('owner_id')
            ->selectRaw('owner_id, count(*) as count')
            ->pluck('count', 'owner_id');

        $followUpQuery = (clone $baseWithOwners)
            ->whereHas('stage', fn (Builder $q) => $q->whereIn('key', ['follow_up']))
            ->groupBy('owner_id')
            ->selectRaw('owner_id, count(*) as count')
            ->pluck('count', 'owner_id');

        $qualifiedQuery = (clone $baseWithOwners)
            ->whereHas('stage', fn (Builder $q) => $q->whereIn('key', $qualifiedKeys))
            ->groupBy('owner_id')
            ->selectRaw('owner_id, count(*) as count')
            ->pluck('count', 'owner_id');

        $cutoff = now()->subDay()->toDateTimeString();
        $pending24Query = (clone $baseWithOwners)
            ->where('created_at', '<', $cutoff)
            ->whereDoesntHave('activities', fn (Builder $q) => $q->where('occurred_at', '>=', $cutoff))
            ->groupBy('owner_id')
            ->selectRaw('owner_id, count(*) as count')
            ->pluck('count', 'owner_id');

        $telecallers = User::query()->with('role')->whereIn('id', $telecallerIds)->get()->keyBy('id');

        $out = [];
        foreach ($telecallerIds as $tid) {
            $user = $telecallers[$tid] ?? null;
            if (! $user) {
                continue;
            }
            
            $assigned = (int) ($assignedQuery[$tid] ?? 0);
            $followUp = (int) ($followUpQuery[$tid] ?? 0);
            $qualified = (int) ($qualifiedQuery[$tid] ?? 0);
            $pending24 = (int) ($pending24Query[$tid] ?? 0);
            
            $contacted = $assigned - $followUp;
            $conv = (int) round(($qualified / max($assigned, 1)) * 100);
            $dept = ($user->department ?? '') === 'IM' ? 'IM' : 'PM';
            
            $out[] = [
                'id' => $user->id,
                'name' => trim($user->first_name.' '.($user->last_name ?? '')),
                'dept' => $dept,
                'assigned' => $assigned,
                'contacted' => max(0, $contacted),
                'qualified' => $qualified,
                'follow_up' => $followUp,
                'conversion' => $conv,
                'pending_24h' => $pending24,
            ];
        }

        usort($out, fn ($a, $b) => $b['conversion'] <=> $a['conversion']);

        return $out;
    }
}
