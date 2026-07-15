<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeadFilterSet;
use Illuminate\Http\Request;

class LeadFilterSetController extends Controller
{
    public function index()
    {
        return response()->json(
            LeadFilterSet::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        $maxOrder = LeadFilterSet::max('sort_order') ?? 0;
        $validated['sort_order'] = $maxOrder + 1;
        $validated['created_by'] = $request->user()?->id;
        $validated['is_active'] = true;

        $filterSet = LeadFilterSet::create($validated);

        return response()->json($filterSet, 201);
    }

    public function update(Request $request, LeadFilterSet $leadFilterSet)
    {
        $validated = $this->validatePayload($request, partial: true);
        $leadFilterSet->update($validated);

        return response()->json($leadFilterSet->fresh());
    }

    public function destroy(LeadFilterSet $leadFilterSet)
    {
        $leadFilterSet->update(['is_active' => false]);

        return response()->json(['message' => 'Filter set deactivated']);
    }

    private function validatePayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'criteria' => [$required, 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
    }
}
