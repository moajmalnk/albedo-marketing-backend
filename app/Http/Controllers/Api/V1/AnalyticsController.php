<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Task;
use App\Services\MarketingAnalyticsService;
use App\Services\RoleDashboardAnalyticsService;
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

    private function assertMarketingDashboardRole(Request $request): void
    {
        $key = $request->user()?->role?->key;
        if (! in_array($key, ['super_admin', 'admin', 'dept_head'], true)) {
            abort(403);
        }
    }
}
