<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class RoleDashboardAnalyticsService
{
    /**
     * @return array<string, mixed>
     */
    public function summarize(Request $request): array
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }
        $user->loadMissing('role');
        $key = $user->role?->key;

        if ($key === 'telecaller') {
            return $this->telecaller($user);
        }
        if ($key === 'marketer') {
            return $this->marketer($user);
        }
        if ($key === 'dept_head') {
            return $this->deptHead($user, $request);
        }
        if (in_array($key, ['sales_head', 'psa', 'advisor'], true)) {
            return $this->salesRole($user, (string) $key);
        }

        abort(403, 'ROLE_SUMMARY_NOT_AVAILABLE');
    }

    /**
     * @return array<string, mixed>
     */
    private function telecaller(User $user): array
    {
        $qualifiedKeys = config('marketing.qualified_stage_keys', ['enrolled', 'itb']);
        $base = Lead::query()->where('owner_id', $user->id);
        $today = now()->toDateString();

        $assignedToday = (int) (clone $base)->whereDate('created_at', $today)->count();
        $closedToday = (int) (clone $base)->whereDate('created_at', $today)
            ->whereHas('stage', fn (Builder $q) => $q->whereIn('key', $qualifiedKeys))
            ->count();

        $followUps = (int) (clone $base)->whereHas('stage', fn (Builder $q) => $q->where('key', 'follow_up'))->count();

        $monthStart = now()->startOfMonth();
        $qualifiedMonth = (int) (clone $base)->where('created_at', '>=', $monthStart)
            ->whereHas('stage', fn (Builder $q) => $q->whereIn('key', $qualifiedKeys))
            ->count();

        return [
            'role' => 'telecaller',
            'assigned_today' => $assignedToday,
            'closed_today' => $closedToday,
            'follow_up_open' => $followUps,
            'qualified_this_month' => $qualifiedMonth,
            'owned_total' => (int) (clone $base)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function marketer(User $user): array
    {
        $base = Lead::query()->where(function (Builder $q) use ($user) {
            $q->where('generated_by_user_id', $user->id)->orWhere('created_by', $user->id);
        });

        $total = (int) (clone $base)->count();
        $qualifiedKeys = config('marketing.qualified_stage_keys', ['enrolled', 'itb']);
        $qualified = (int) (clone $base)->whereHas('stage', fn (Builder $q) => $q->whereIn('key', $qualifiedKeys))->count();
        $meta = (int) (clone $base)->where(function (Builder $q) {
            $q->whereRaw('LOWER(COALESCE(campaign, "")) LIKE ?', ['%meta%'])
                ->orWhereRaw('LOWER(COALESCE(source_code, "")) LIKE ?', ['%meta%']);
        })->count();
        $google = (int) (clone $base)->where(function (Builder $q) {
            $q->whereRaw('LOWER(COALESCE(campaign, "")) LIKE ?', ['%google%'])
                ->orWhereRaw('LOWER(COALESCE(source_code, "")) LIKE ?', ['%google%']);
        })->count();

        $dailyVolume = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = Carbon::today()->subDays($i);
            $dailyVolume[] = [
                'date' => $d->format('d M'),
                'leads' => (int) (clone $base)->whereDate('created_at', $d->toDateString())->count(),
            ];
        }

        $since = now()->subDays(30);
        $recentCreated = (int) (clone $base)->where('created_at', '>=', $since)->count();

        return [
            'role' => 'marketer',
            'total_leads' => $total,
            'qualified' => $qualified,
            'quality_percent' => $total > 0 ? (int) round(($qualified / $total) * 100) : 0,
            'meta_leads' => $meta,
            'google_leads' => $google,
            'other_leads' => max(0, $total - $meta - $google),
            'daily_volume' => $dailyVolume,
            'import_success_proxy_percent' => $total > 0 ? (int) round(($qualified / $total) * 100) : 0,
            'leads_last_30_days' => $recentCreated,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deptHead(User $user, Request $request): array
    {
        $marketing = app(MarketingAnalyticsService::class);
        $merged = $request->query->all();
        $team = match ($user->department) {
            'IM' => 'influence',
            'PM' => 'performance',
            default => null,
        };
        if ($team !== null) {
            $merged['marketing_team'] = $team;
        }
        $scoped = Request::create('/api/v1/analytics/marketing', 'GET', $merged);
        $scoped->setUserResolver(fn () => $user);

        return array_merge(['role' => 'dept_head'], $marketing->summarize($scoped));
    }

    /**
     * @return array<string, mixed>
     */
    private function salesRole(User $user, string $key): array
    {
        $base = Lead::query();
        if ($key === 'advisor') {
            $base->where('owner_id', $user->id);
        }

        $countStage = fn (string $stageKey) => (int) (clone $base)->whereHas('stage', fn (Builder $q) => $q->where('key', $stageKey))->count();

        $today = now()->toDateString();
        $leadsToday = (int) (clone $base)->whereDate('created_at', $today)->count();

        $psaQueue = (int) (clone $base)->whereHas('stage', fn (Builder $q) => $q->whereIn('key', ['new_lead', 'prospect']))->count();
        $itb = $countStage('itb');
        $enrolled = $countStage('enrolled');
        $assessmentBooked = $countStage('assessment_booked');
        $assessmentDone = $countStage('assessment_done');
        $open = (int) (clone $base)->whereHas('stage', fn (Builder $q) => $q->whereNotIn('key', [
            'enrolled', 'nifc', 'first_call_nifc', 'invalid_junk', 'disqualified', 'duplicate_lead', 'natc', 'job_enquiry',
        ]))->count();

        $recentActivities = LeadActivity::query()
            ->with(['lead' => fn ($q) => $q->select('id', 'student_name', 'phone')])
            ->when($key === 'advisor', fn (Builder $q) => $q->where('user_id', $user->id))
            ->orderByDesc('occurred_at')
            ->limit(12)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->type,
                'comments' => $a->comments,
                'occurred_at' => $a->occurred_at?->toIso8601String(),
                'lead_id' => $a->lead_id,
                'student_name' => $a->lead?->student_name,
            ]);

        return [
            'role' => $key,
            'leads_today' => $leadsToday,
            'stage_counts' => [
                'new_lead' => $countStage('new_lead'),
                'prospect' => $countStage('prospect'),
                'itb' => $itb,
                'assessment_booked' => $assessmentBooked,
                'assessment_done' => $assessmentDone,
                'enrolled' => $enrolled,
            ],
            'psa_screening_queue' => $psaQueue,
            'open_pipeline' => $open,
            'recent_activities' => $recentActivities,
            ...($key === 'advisor' ? [
                'itb_queue_count' => (int) (clone $base)->whereHas('stage', fn (Builder $q) => $q->where('key', 'itb'))->count(),
                'my_admissions' => $enrolled,
                'my_active_pipeline' => (int) (clone $base)->whereHas('stage', fn (Builder $q) => $q->whereNotIn('key', [
                    'enrolled', 'nifc', 'first_call_nifc', 'invalid_junk', 'disqualified', 'duplicate_lead', 'natc', 'job_enquiry',
                ]))->count(),
            ] : []),
            ...($key === 'psa' ? [
                'assessments_done_total' => $assessmentDone,
                'handed_off_mine' => 0,
            ] : []),
        ];
    }
}
