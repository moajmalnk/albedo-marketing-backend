<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeadScoringRule;
use App\Services\LeadScoringService;
use Illuminate\Http\Request;

class LeadScoringRuleController extends Controller
{
    public function index()
    {
        return LeadScoringRule::all();
    }

    public function store(Request $request, LeadScoringService $scoringService)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'description' => 'nullable|string',
            'condition_field' => 'required|string',
            'condition_operator' => 'required|string',
            'condition_value' => 'nullable|string',
            'points' => 'required|integer',
            'is_active' => 'boolean',
        ]);

        $rule = LeadScoringRule::create($validated);
        
        // Recalculate leads
        $scoringService->recalculateAll();

        return response()->json($rule, 201);
    }

    public function update(Request $request, LeadScoringRule $leadScoringRule, LeadScoringService $scoringService)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string',
            'description' => 'nullable|string',
            'condition_field' => 'sometimes|string',
            'condition_operator' => 'sometimes|string',
            'condition_value' => 'nullable|string',
            'points' => 'sometimes|integer',
            'is_active' => 'boolean',
        ]);

        $leadScoringRule->update($validated);
        
        // Recalculate leads
        $scoringService->recalculateAll();

        return response()->json($leadScoringRule);
    }

    public function destroy(LeadScoringRule $leadScoringRule, LeadScoringService $scoringService)
    {
        $leadScoringRule->delete();
        
        // Recalculate leads
        $scoringService->recalculateAll();

        return response()->json(null, 204);
    }
}
