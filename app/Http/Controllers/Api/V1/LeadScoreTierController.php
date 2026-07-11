<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeadScoreTier;
use App\Services\LeadScoringService;
use Illuminate\Http\Request;

class LeadScoreTierController extends Controller
{
    public function index()
    {
        return LeadScoreTier::orderBy('min_score', 'desc')->get();
    }

    public function update(Request $request, LeadScoreTier $leadScoreTier, LeadScoringService $scoringService)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string',
            'min_score' => 'sometimes|integer',
            'max_score' => 'nullable|integer',
        ]);

        $leadScoreTier->update($validated);
        
        // Recalculate leads
        $scoringService->recalculateAll();

        return response()->json($leadScoreTier);
    }
}
