<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Task;
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
}
