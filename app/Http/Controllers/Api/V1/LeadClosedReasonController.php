<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeadClosedReason;
use Illuminate\Http\Request;

class LeadClosedReasonController extends Controller
{
    public function index()
    {
        return response()->json(
            LeadClosedReason::query()->where('is_active', true)->orderBy('sort_order')->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'key' => 'required|string|max:40|unique:lead_closed_reasons,key',
            'label' => 'required|string|max:80',
            'color' => 'nullable|string|max:16',
            'description' => 'nullable|string',
        ]);

        $maxOrder = LeadClosedReason::max('sort_order') ?? 0;
        $validated['sort_order'] = $maxOrder + 1;

        $reason = LeadClosedReason::create($validated);
        return response()->json($reason, 201);
    }

    public function update(Request $request, LeadClosedReason $leadClosedReason)
    {
        $validated = $request->validate([
            'label' => 'sometimes|string|max:80',
            'color' => 'sometimes|nullable|string|max:16',
            'description' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $leadClosedReason->update($validated);
        return response()->json($leadClosedReason);
    }

    public function destroy(LeadClosedReason $leadClosedReason)
    {
        $leadClosedReason->update(['is_active' => false]);
        return response()->json(['message' => 'Closed reason deactivated']);
    }
}
