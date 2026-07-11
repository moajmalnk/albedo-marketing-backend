<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Task;
use App\Services\MarketingAnalyticsService;
use App\Services\RoleDashboardAnalyticsService;
use App\Services\TeamInsightsAnalyticsService;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function productivity(Request $request)
    {
        $totalLeads = Lead::query()->count();
        $enrolled = Lead::query()->whereHas('stage', fn ($q) => $q->where('key', 'enrolled'))->count();
        $conversionRate = $totalLeads > 0 ? round(($enrolled / $totalLeads) * 100, 2) : 0;

        return response()->json([
            'total_leads' => $totalLeads,
            'conversion_rate' => $conversionRate,
            'activities_count' => LeadActivity::query()->count(),
            'task_completion' => [
                'completed' => Task::query()->where('status', 'completed')->count(),
                'total' => Task::query()->count(),
            ],
        ]);
    }

    public function marketing(Request $request, MarketingAnalyticsService $marketingAnalyticsService)
    {
        $request->user()?->loadMissing('role');
        $this->assertMarketingDashboardRole($request);

        return response()->json($marketingAnalyticsService->summarize($request));
    }

    public function roleSummary(Request $request, RoleDashboardAnalyticsService $roleDashboardAnalyticsService)
    {
        $request->user()?->loadMissing('role');

        return response()->json($roleDashboardAnalyticsService->summarize($request));
    }

    public function teamInsights(Request $request, TeamInsightsAnalyticsService $teamInsightsAnalyticsService)
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $request->user()?->loadMissing('role');
        $this->assertTeamInsightsRole($request);

        return response()->json($teamInsightsAnalyticsService->summarize($request));
    }

    public function leadQuality(Request $request)
    {
        $this->assertMarketingDashboardRole($request);

        $totalLeads = Lead::count();
        $qualifiedStageIds = \App\Models\LeadStage::whereIn('key', ['qualified', 'sales_head_review', 'advisor_counselling', 'returned_to_advisor', 'enrolled'])->pluck('id');
        $dupReasonId = \App\Models\LeadClosedReason::where('key', 'duplicate')->value('id') ?: 0;
        $wrongNumReasonId = \App\Models\LeadClosedReason::where('key', 'wrong_number')->value('id') ?: 0;
        $fakeReasonId = \App\Models\LeadClosedReason::where('key', 'fake_lead')->value('id') ?: 0;
        $junkClosedReasonIds = \App\Models\LeadClosedReason::whereIn('key', ['not_interested', 'no_response', 'parent_rejected'])->pluck('id');

        $stats = Lead::selectRaw('
            SUM(CASE WHEN stage_id IN ('.($qualifiedStageIds->isEmpty() ? '0' : $qualifiedStageIds->implode(',')).') THEN 1 ELSE 0 END) as qualified_leads,
            SUM(CASE WHEN score >= 80 THEN 1 ELSE 0 END) as high_intent,
            SUM(CASE WHEN closed_reason_id = ? THEN 1 ELSE 0 END) as duplicates,
            SUM(CASE WHEN closed_reason_id = ? THEN 1 ELSE 0 END) as wrong_numbers,
            SUM(CASE WHEN closed_reason_id = ? THEN 1 ELSE 0 END) as invalid_leads,
            SUM(CASE WHEN closed_reason_id IN ('.($junkClosedReasonIds->isEmpty() ? '0' : $junkClosedReasonIds->implode(',')).') THEN 1 ELSE 0 END) as junk_leads,
            SUM(CASE WHEN score >= 80 THEN 1 ELSE 0 END) as qd_high,
            SUM(CASE WHEN score BETWEEN 50 AND 79 THEN 1 ELSE 0 END) as qd_warm,
            SUM(CASE WHEN score BETWEEN 20 AND 49 THEN 1 ELSE 0 END) as qd_cold,
            SUM(CASE WHEN score < 20 THEN 1 ELSE 0 END) as qd_junk,
            SUM(CASE WHEN score BETWEEN 0 AND 20 THEN 1 ELSE 0 END) as sd_0_20,
            SUM(CASE WHEN score BETWEEN 21 AND 40 THEN 1 ELSE 0 END) as sd_21_40,
            SUM(CASE WHEN score BETWEEN 41 AND 60 THEN 1 ELSE 0 END) as sd_41_60,
            SUM(CASE WHEN score BETWEEN 61 AND 80 THEN 1 ELSE 0 END) as sd_61_80,
            SUM(CASE WHEN score BETWEEN 81 AND 100 THEN 1 ELSE 0 END) as sd_81_100
        ', [$dupReasonId, $wrongNumReasonId, $fakeReasonId])->first();

        // Quality distribution
        $qualityDistribution = [
            ['name' => 'High Intent', 'value' => (int) ($stats->qd_high ?? 0), 'color' => '#10b981'],
            ['name' => 'Warm', 'value' => (int) ($stats->qd_warm ?? 0), 'color' => '#f59e0b'],
            ['name' => 'Cold', 'value' => (int) ($stats->qd_cold ?? 0), 'color' => '#3b82f6'],
            ['name' => 'Junk', 'value' => (int) ($stats->qd_junk ?? 0), 'color' => '#ef4444'],
        ];

        // Score Distribution
        $scoreDistribution = [
            ['score' => '0-20', 'leads' => (int) ($stats->sd_0_20 ?? 0)],
            ['score' => '21-40', 'leads' => (int) ($stats->sd_21_40 ?? 0)],
            ['score' => '41-60', 'leads' => (int) ($stats->sd_41_60 ?? 0)],
            ['score' => '61-80', 'leads' => (int) ($stats->sd_61_80 ?? 0)],
            ['score' => '81-100', 'leads' => (int) ($stats->sd_81_100 ?? 0)],
        ];

        // Conversion by Campaign
        $campaigns = \App\Models\Campaign::withCount([
            'leads',
            'leads as qualified' => fn($q) => $q->whereIn('stage_id', $qualifiedStageIds)
        ])->orderByDesc('leads_count')->limit(5)->get()->map(fn($c) => [
            'name' => $c->name,
            'qualified' => $c->qualified,
            'total' => $c->leads_count
        ]);

        // Recent Flags
        // We find recent leads that either have score >= 80 or have duplicate / fake lead closed reasons
        $recentFlags = Lead::with(['campaign'])
            ->where(function($q) use ($dupReasonId, $fakeReasonId) {
                $q->where('score', '>=', 80);
                if ($dupReasonId) {
                    $q->orWhere('closed_reason_id', $dupReasonId);
                }
                if ($fakeReasonId) {
                    $q->orWhere('closed_reason_id', $fakeReasonId);
                }
            })
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(function($l) use ($dupReasonId, $fakeReasonId) {
                $flag = 'Warm';
                if ($l->score >= 80) $flag = 'High Intent';
                if ($dupReasonId && $l->closed_reason_id == $dupReasonId) $flag = 'Duplicate';
                if ($fakeReasonId && $l->closed_reason_id == $fakeReasonId) $flag = 'Invalid';

                return [
                    'id' => $l->id,
                    'name' => $l->student_name ?: 'Anonymous',
                    'source' => $l->source_code ?: ($l->campaign?->name ?: 'Organic'),
                    'flag' => $flag
                ];
            });

        return response()->json([
            'metrics' => [
                'qualified' => (int) ($stats->qualified_leads ?? 0),
                'high_intent' => (int) ($stats->high_intent ?? 0),
                'duplicates' => (int) ($stats->duplicates ?? 0),
                'wrong_numbers' => (int) ($stats->wrong_numbers ?? 0),
                'invalid' => (int) ($stats->invalid_leads ?? 0),
                'junk' => (int) ($stats->junk_leads ?? 0),
            ],
            'quality_distribution' => $qualityDistribution,
            'score_distribution' => $scoreDistribution,
            'conversion_by_campaign' => $campaigns,
            'recent_flags' => $recentFlags
        ]);
    }

    private function assertTeamInsightsRole(Request $request): void
    {
        $key = $request->user()?->role?->key;
        if (! in_array($key, ['super_admin', 'admin'], true)) {
            abort(403);
        }
    }

    private function assertMarketingDashboardRole(Request $request): void
    {
        $key = $request->user()?->role?->key;
        if (! in_array($key, ['super_admin', 'admin', 'dept_head', 'department_head', 'marketer'], true)) {
            abort(403);
        }
    }
}
