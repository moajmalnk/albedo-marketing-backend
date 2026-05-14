<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Super-admin Analytics page: per-role team rows + org charts (date-scoped).
 */
class TeamInsightsAnalyticsService
{
    /** All-time queries are capped to this many years for DB safety. */
    private const ALL_TIME_CAP_YEARS = 2;

    /** Max calendar months returned for monthly_trend. */
    private const MAX_MONTHLY_BUCKETS = 12;

    /** Max source rows for pie chart. */
    private const SOURCE_PIE_LIMIT = 10;

    /**
     * @return array<string, mixed>
     */
    public function summarize(Request $request): array
    {
        [$rangeStart, $rangeEnd, $capApplied] = $this->parseDateRange($request);

        $qualifiedKeys = config('marketing.qualified_stage_keys', ['enrolled', 'itb']);

        $base = Lead::query();
        $this->applyLeadDateRange($base, $rangeStart, $rangeEnd);

        $orgTotal = (int) (clone $base)->count();
        $orgQualified = (int) (clone $base)->whereHas('stage', fn (Builder $q) => $q->whereIn('key', $qualifiedKeys))->count();

        return [
            'from' => $rangeStart->toDateString(),
            'to' => $rangeEnd->toDateString(),
            'date_cap_applied' => $capApplied,
            'qualified_stage_keys' => $qualifiedKeys,
            'team' => [
                'telecaller' => $this->teamTelecallers($rangeStart, $rangeEnd, $qualifiedKeys),
                'marketer' => $this->teamMarketers($rangeStart, $rangeEnd, $qualifiedKeys),
                'department_head' => $this->teamDepartmentHeads($rangeStart, $rangeEnd, $qualifiedKeys),
                'admin' => $this->teamAdmins($orgTotal, $orgQualified),
            ],
            'charts' => [
                'monthly_trend' => $this->monthlyTrend(clone $base, $qualifiedKeys, $rangeStart, $rangeEnd),
                'weekly' => $this->weeklySeries(clone $base, $qualifiedKeys, $rangeStart, $rangeEnd),
                'platform' => $this->platformBreakdown(clone $base, $qualifiedKeys),
                'sources' => $this->sourcePie(clone $base),
            ],
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: bool}
     */
    private function parseDateRange(Request $request): array
    {
        $fromStr = $request->query('from');
        $toStr = $request->query('to');

        if ($fromStr || $toStr) {
            $from = $fromStr ? Carbon::parse((string) $fromStr)->startOfDay() : Carbon::parse((string) $toStr)->startOfDay();
            $to = $toStr ? Carbon::parse((string) $toStr)->endOfDay() : now()->endOfDay();
            if ($fromStr && ! $toStr) {
                $to = now()->endOfDay();
            }
            if (! $fromStr && $toStr) {
                $from = $to->copy()->subYears(self::ALL_TIME_CAP_YEARS)->startOfDay();
            }

            if ($from->gt($to)) {
                [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            }

            return [$from, $to, false];
        }

        $end = now()->endOfDay();
        $start = now()->subYears(self::ALL_TIME_CAP_YEARS)->startOfDay();

        return [$start, $end, true];
    }

    private function applyLeadDateRange(Builder $query, Carbon $start, Carbon $end): void
    {
        $query->whereBetween('created_at', [$start, $end]);
    }

    /**
     * @param  list<string>  $qualifiedKeys
     * @return list<array{user_id: int, name: string, department: string, total_leads: int, qualified_leads: int, conversion_percent: int}>
     */
    private function teamTelecallers(Carbon $rangeStart, Carbon $rangeEnd, array $qualifiedKeys): array
    {
        $users = User::query()
            ->where('status', 'active')
            ->whereHas('role', fn (Builder $q) => $q->where('key', 'telecaller'))
            ->orderBy('first_name')
            ->get();

        $out = [];
        foreach ($users as $user) {
            $q = Lead::query()->where('owner_id', $user->id);
            $this->applyLeadDateRange($q, $rangeStart, $rangeEnd);
            $total = (int) $q->count();
            $qualified = (int) (clone $q)->whereHas('stage', fn (Builder $b) => $b->whereIn('key', $qualifiedKeys))->count();
            $out[] = $this->teamRow($user, $total, $qualified);
        }

        usort($out, fn ($a, $b) => $b['conversion_percent'] <=> $a['conversion_percent']);

        return $out;
    }

    /**
     * @param  list<string>  $qualifiedKeys
     * @return list<array{user_id: int, name: string, department: string, total_leads: int, qualified_leads: int, conversion_percent: int}>
     */
    private function teamMarketers(Carbon $rangeStart, Carbon $rangeEnd, array $qualifiedKeys): array
    {
        $users = User::query()
            ->where('status', 'active')
            ->whereHas('role', fn (Builder $q) => $q->where('key', 'marketer'))
            ->orderBy('first_name')
            ->get();

        $out = [];
        foreach ($users as $user) {
            $q = Lead::query()->where(function (Builder $b) use ($user) {
                $b->where('generated_by_user_id', $user->id)->orWhere('created_by', $user->id);
            });
            $this->applyLeadDateRange($q, $rangeStart, $rangeEnd);
            $total = (int) $q->count();
            $qualified = (int) (clone $q)->whereHas('stage', fn (Builder $b) => $b->whereIn('key', $qualifiedKeys))->count();
            $out[] = $this->teamRow($user, $total, $qualified);
        }

        usort($out, fn ($a, $b) => $b['conversion_percent'] <=> $a['conversion_percent']);

        return $out;
    }

    /**
     * @param  list<string>  $qualifiedKeys
     * @return list<array{user_id: int, name: string, department: string, total_leads: int, qualified_leads: int, conversion_percent: int}>
     */
    private function teamDepartmentHeads(Carbon $rangeStart, Carbon $rangeEnd, array $qualifiedKeys): array
    {
        $users = User::query()
            ->where('status', 'active')
            ->whereHas('role', fn (Builder $q) => $q->whereIn('key', ['dept_head', 'department_head']))
            ->orderBy('first_name')
            ->get();

        $out = [];
        foreach ($users as $dh) {
            $tcIds = User::query()
                ->where('reporting_manager_id', $dh->id)
                ->whereHas('role', fn (Builder $q) => $q->where('key', 'telecaller'))
                ->pluck('id');
            $q = Lead::query()->whereIn('owner_id', $tcIds);
            $this->applyLeadDateRange($q, $rangeStart, $rangeEnd);
            $total = (int) $q->count();
            $qualified = (int) (clone $q)->whereHas('stage', fn (Builder $b) => $b->whereIn('key', $qualifiedKeys))->count();
            $out[] = $this->teamRow($dh, $total, $qualified);
        }

        usort($out, fn ($a, $b) => $b['conversion_percent'] <=> $a['conversion_percent']);

        return $out;
    }

    /**
     * @param  list<string>  $qualifiedKeys
     * @return list<array{user_id: int, name: string, department: string, total_leads: int, qualified_leads: int, conversion_percent: int}>
     */
    private function teamAdmins(int $orgTotal, int $orgQualified): array
    {
        $users = User::query()
            ->where('status', 'active')
            ->whereHas('role', fn (Builder $q) => $q->whereIn('key', ['admin', 'super_admin']))
            ->orderBy('first_name')
            ->get();

        $rate = $orgTotal > 0 ? (int) round(($orgQualified / $orgTotal) * 100) : 0;
        $out = [];
        foreach ($users as $user) {
            $out[] = [
                'user_id' => $user->id,
                'name' => $this->userDisplayName($user),
                'department' => $user->department ?: '—',
                'total_leads' => $orgTotal,
                'qualified_leads' => $orgQualified,
                'conversion_percent' => $rate,
            ];
        }

        usort($out, fn ($a, $b) => $b['conversion_percent'] <=> $a['conversion_percent']);

        return $out;
    }

    /**
     * @return array{user_id: int, name: string, department: string, total_leads: int, qualified_leads: int, conversion_percent: int}
     */
    private function teamRow(User $user, int $total, int $qualified): array
    {
        $rate = $total > 0 ? (int) round(($qualified / $total) * 100) : 0;

        return [
            'user_id' => $user->id,
            'name' => $this->userDisplayName($user),
            'department' => $user->department ?: '—',
            'total_leads' => $total,
            'qualified_leads' => $qualified,
            'conversion_percent' => $rate,
        ];
    }

    private function userDisplayName(User $user): string
    {
        return trim($user->first_name.' '.($user->last_name ?? '')) ?: ($user->email ?? 'User #'.$user->id);
    }

    /**
     * @param  list<string>  $qualifiedKeys
     * @return list<array{month: string, leads: int, qualified: int}>
     */
    private function monthlyTrend(Builder $base, array $qualifiedKeys, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $startMonth = $rangeStart->copy()->startOfMonth();
        $endMonth = $rangeEnd->copy()->startOfMonth();
        $monthsSpan = (int) $startMonth->diffInMonths($endMonth) + 1;
        if ($monthsSpan > self::MAX_MONTHLY_BUCKETS) {
            $startMonth = $endMonth->copy()->subMonths(self::MAX_MONTHLY_BUCKETS - 1);
        }

        if ($startMonth->gt($endMonth)) {
            return [];
        }

        $out = [];
        for ($m = $startMonth->copy(); $m <= $endMonth; $m->addMonth()) {
            $ms = $m->copy()->startOfMonth();
            $me = $m->copy()->endOfMonth();
            $leads = (int) (clone $base)->whereBetween('created_at', [$ms, $me])->count();
            $qualified = (int) (clone $base)->whereBetween('created_at', [$ms, $me])
                ->whereHas('stage', fn (Builder $q) => $q->whereIn('key', $qualifiedKeys))
                ->count();
            $out[] = [
                'month' => $ms->format('M Y'),
                'leads' => $leads,
                'qualified' => $qualified,
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>  $qualifiedKeys
     * @return list<array{day: string, leads: int, qualified: int}>
     */
    private function weeklySeries(Builder $base, array $qualifiedKeys, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $endD = $rangeEnd->copy()->startOfDay()->min(now()->startOfDay());
        $out = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = $endD->copy()->subDays($i);
            $dayStr = $day->toDateString();
            if ($day->lt($rangeStart->copy()->startOfDay())) {
                $out[] = ['day' => $day->format('D'), 'leads' => 0, 'qualified' => 0];

                continue;
            }
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
     * Mutually exclusive buckets: Meta, then Google, then Website, remainder Other.
     *
     * @param  list<string>  $qualifiedKeys
     * @return list<array{platform: string, total: int, qualified: int}>
     */
    private function platformBreakdown(Builder $base, array $qualifiedKeys): array
    {
        $metaSql = '(LOWER(COALESCE(campaign, \'\')) LIKE \'%meta%\' OR LOWER(COALESCE(source_code, \'\')) LIKE \'%meta%\')';
        $googleSql = '(LOWER(COALESCE(campaign, \'\')) LIKE \'%google%\' OR LOWER(COALESCE(source_code, \'\')) LIKE \'%google%\')';
        $websiteSql = '(campaign IS NULL AND (source_code IS NULL OR source_code = \'import\'))';

        $metaQ = (clone $base)->whereRaw($metaSql);
        $metaTotal = (int) $metaQ->count();
        $metaQual = (int) (clone $metaQ)->whereHas('stage', fn (Builder $b) => $b->whereIn('key', $qualifiedKeys))->count();

        $afterMeta = (clone $base)->whereRaw('NOT '.$metaSql);
        $googleQ = (clone $afterMeta)->whereRaw($googleSql);
        $googleTotal = (int) $googleQ->count();
        $googleQual = (int) (clone $googleQ)->whereHas('stage', fn (Builder $b) => $b->whereIn('key', $qualifiedKeys))->count();

        $afterGoogle = (clone $afterMeta)->whereRaw('NOT '.$googleSql);
        $webQ = (clone $afterGoogle)->whereRaw($websiteSql);
        $webTotal = (int) $webQ->count();
        $webQual = (int) (clone $webQ)->whereHas('stage', fn (Builder $b) => $b->whereIn('key', $qualifiedKeys))->count();

        $otherQ = (clone $afterGoogle)->whereRaw('NOT '.$websiteSql);
        $otherTotal = (int) $otherQ->count();
        $otherQual = (int) (clone $otherQ)->whereHas('stage', fn (Builder $b) => $b->whereIn('key', $qualifiedKeys))->count();

        return [
            ['platform' => 'Meta', 'total' => $metaTotal, 'qualified' => $metaQual],
            ['platform' => 'Google', 'total' => $googleTotal, 'qualified' => $googleQual],
            ['platform' => 'Website', 'total' => $webTotal, 'qualified' => $webQual],
            ['platform' => 'Other', 'total' => $otherTotal, 'qualified' => $otherQual],
        ];
    }

    /**
     * @return list<array{source: string, leads: int}>
     */
    private function sourcePie(Builder $base): array
    {
        $rows = (clone $base)
            ->selectRaw('COALESCE(NULLIF(TRIM(source_code), \'\'), \'unknown\') as src')
            ->selectRaw('COUNT(*) as c')
            ->groupBy('src')
            ->orderByDesc('c')
            ->limit(self::SOURCE_PIE_LIMIT)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'source' => (string) $row->src,
                'leads' => (int) $row->c,
            ];
        }

        return $out;
    }
}
