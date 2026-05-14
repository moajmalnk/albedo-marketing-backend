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
                $query->whereMonth('created_at', $m);
            }
        }

        $year = $request->input('year');
        if ($year !== null && $year !== '' && $year !== 'All') {
            $y = (int) $year;
            if ($y >= 2000 && $y <= 2100) {
                $query->whereYear('created_at', $y);
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
        $stageKeys = $this->statusFilterToStageKeys((string) $statusFilter);
        if ($stageKeys !== null) {
            $query->whereHas('stage', fn (Builder $q) => $q->whereIn('key', $stageKeys));
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
     * @return list<string>|null null = no filter
     */
    private function statusFilterToStageKeys(string $statusFilter): ?array
    {
        return match ($statusFilter) {
            'Qualified' => config('marketing.qualified_stage_keys'),
            'Follow-up' => ['follow_up', 'prospect', 'demo_required', 'dnp'],
            'Not Interested' => ['nifc', 'first_call_nifc', 'natc'],
            'Fraud' => ['invalid_junk', 'disqualified', 'duplicate_lead'],
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function summarize(Request $request): array
    {
        $base = $this->filteredLeadQuery($request);
        $qualifiedKeys = config('marketing.qualified_stage_keys', ['enrolled', 'itb']);

        $countChannel = function (Builder $source, string $channel): int {
            $q = clone $source;
            LeadChannelClassifier::applyChannelFilter($q, $channel);

            return (int) $q->count();
        };

        $whatsapp = $countChannel($base, LeadChannelClassifier::WHATSAPP);
        $form = $countChannel($base, LeadChannelClassifier::FORM);
        $call = $countChannel($base, LeadChannelClassifier::CALL);
        $message = $countChannel($base, LeadChannelClassifier::MESSAGE);

        $totalLeads = (int) (clone $base)->count();
        $qualifiedLeads = (int) (clone $base)->whereHas('stage', fn (Builder $q) => $q->whereIn('key', $qualifiedKeys))->count();
        $followUpLeads = (int) (clone $base)->whereHas('stage', fn (Builder $q) => $q->whereIn('key', ['follow_up', 'prospect', 'demo_required', 'dnp']))->count();

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

        $todayRate = $this->todayConversionRate(clone $base, $qualifiedKeys);

        $trends = $this->channelMonthOverMonth();

        $activeUsers = User::query()->where('status', 'active')
            ->where('last_login_at', '>=', now()->subDay())
            ->count();
        $totalActiveStaff = User::query()->where('status', 'active')->count();

        $checkedInToday = (int) AttendanceLog::query()
            ->whereDate('day_date', now()->toDateString())
            ->whereNull('check_out_at')
            ->distinct()
            ->count('user_id');

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
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => trim($u->first_name.' '.($u->last_name ?? '')),
                'role_key' => $u->role?->key,
                'last_login_at' => $u->last_login_at?->toIso8601String(),
                'online_last_24h' => $u->last_login_at && $u->last_login_at->gte(now()->subDay()),
            ]);

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
                'active_users_denominator' => $totalActiveStaff,
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
        ];
    }

    /**
     * @param  list<string>  $qualifiedKeys
     * @return list<array{day: string, leads: int, qualified: int}>
     */
    private function weeklySeries(Builder $base, array $qualifiedKeys): array
    {
        $out = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = Carbon::today()->subDays($i);
            $dayStr = $day->toDateString();
            $leads = (int) (clone $base)->whereDate('created_at', $dayStr)->count();
            $qualified = (int) (clone $base)->whereDate('created_at', $dayStr)
                ->whereHas('stage', fn (Builder $q) => $q->whereIn('key', $qualifiedKeys))
                ->count();
            $out[] = [
                'day' => $day->format('D'),
                'leads' => $leads,
                'qualified' => $qualified,
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>  $qualifiedKeys
     */
    private function todayConversionRate(Builder $base, array $qualifiedKeys): int
    {
        $today = now()->toDateString();
        $t = (int) (clone $base)->whereDate('created_at', $today)->count();
        $q = (int) (clone $base)->whereDate('created_at', $today)
            ->whereHas('stage', fn (Builder $b) => $b->whereIn('key', $qualifiedKeys))
            ->count();

        return $t > 0 ? (int) round(($q / $t) * 100) : 0;
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

        $countRange = function (Carbon $from, Carbon $to, string $channel): int {
            $q = Lead::query()->whereBetween('created_at', [$from, $to]);
            LeadChannelClassifier::applyChannelFilter($q, $channel);

            return (int) $q->count();
        };

        $thisMonthEnd = now();
        $waT = $countRange($startThis, $thisMonthEnd, LeadChannelClassifier::WHATSAPP);
        $waP = $countRange($startPrev, $endPrev, LeadChannelClassifier::WHATSAPP);

        $formT = $countRange($startThis, $thisMonthEnd, LeadChannelClassifier::FORM);
        $formP = $countRange($startPrev, $endPrev, LeadChannelClassifier::FORM);

        $callT = $countRange($startThis, $thisMonthEnd, LeadChannelClassifier::CALL);
        $callP = $countRange($startPrev, $endPrev, LeadChannelClassifier::CALL);

        $msgT = $countRange($startThis, $thisMonthEnd, LeadChannelClassifier::MESSAGE);
        $msgP = $countRange($startPrev, $endPrev, LeadChannelClassifier::MESSAGE);

        $totalT = (int) Lead::query()->whereBetween('created_at', [$startThis, $thisMonthEnd])->count();
        $totalP = (int) Lead::query()->whereBetween('created_at', [$startPrev, $endPrev])->count();

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

        $out = [];
        foreach ($rows as $row) {
            $src = (string) $row->src;
            $leads = (int) $row->c;
            $qualified = (int) (clone $base)->whereRaw('COALESCE(NULLIF(TRIM(source_code), \'\'), \'unknown\') = ?', [$src])
                ->whereHas('stage', fn (Builder $q) => $q->whereIn('key', $qualifiedKeys))
                ->count();
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
        $niKeys = ['nifc', 'first_call_nifc', 'natc'];
        $fuKeys = ['follow_up', 'prospect', 'demo_required', 'dnp'];

        $bucket = function (string $sourceGroup) use ($base, $qualifiedKeys, $niKeys, $fuKeys): array {
            $q = (clone $base)->where('source_group', $sourceGroup);
            $total = (int) (clone $q)->count();
            $qual = (int) (clone $q)->whereHas('stage', fn (Builder $b) => $b->whereIn('key', $qualifiedKeys))->count();
            $ni = (int) (clone $q)->whereHas('stage', fn (Builder $b) => $b->whereIn('key', $niKeys))->count();
            $fu = (int) (clone $q)->whereHas('stage', fn (Builder $b) => $b->whereIn('key', $fuKeys))->count();
            $conv = $total > 0 ? (int) round(($qual / $total) * 100) : 0;

            return [
                'total_leads' => $total,
                'qualified' => $qual,
                'not_interested' => $ni,
                'follow_ups' => $fu,
                'conversion_percent' => $conv,
            ];
        };

        $pm = $bucket('performance');
        $im = $bucket('influence');

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

        $out = [];
        foreach ($telecallerIds as $tid) {
            $ownerBase = (clone $base)->where('owner_id', $tid);
            $assigned = (int) (clone $ownerBase)->count();
            $user = User::query()->with('role')->find($tid);
            if (! $user) {
                continue;
            }
            $followUp = (int) (clone $ownerBase)->whereHas('stage', fn (Builder $q) => $q->whereIn('key', ['follow_up']))->count();
            $qualified = (int) (clone $ownerBase)->whereHas('stage', fn (Builder $q) => $q->whereIn('key', $qualifiedKeys))->count();
            $contacted = $assigned - $followUp;
            $conv = (int) round(($qualified / max($assigned, 1)) * 100);
            $cutoff = now()->subDay()->toDateTimeString();
            $pending24 = (int) (clone $ownerBase)
                ->where('created_at', '<', $cutoff)
                ->whereDoesntHave('activities', fn (Builder $q) => $q->where('occurred_at', '>=', $cutoff))
                ->count();
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
