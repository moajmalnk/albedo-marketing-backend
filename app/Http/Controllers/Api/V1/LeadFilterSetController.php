<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeadFilterSet;
use Illuminate\Http\Request;

class LeadFilterSetController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()?->id;

        return response()->json(
            LeadFilterSet::query()
                ->where('is_active', true)
                ->where('created_by', $userId)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        $userId = $request->user()?->id;
        $maxOrder = LeadFilterSet::query()
            ->where('created_by', $userId)
            ->max('sort_order') ?? 0;

        $validated['sort_order'] = $maxOrder + 1;
        $validated['created_by'] = $userId;
        $validated['is_active'] = true;

        $filterSet = LeadFilterSet::create($validated);

        return response()->json($filterSet, 201);
    }

    public function update(Request $request, LeadFilterSet $leadFilterSet)
    {
        if ($response = $this->ensureOwner($request, $leadFilterSet)) {
            return $response;
        }

        $validated = $this->validatePayload($request, partial: true);
        $leadFilterSet->update($validated);

        return response()->json($leadFilterSet->fresh());
    }

    public function destroy(Request $request, LeadFilterSet $leadFilterSet)
    {
        if ($response = $this->ensureOwner($request, $leadFilterSet)) {
            return $response;
        }

        $leadFilterSet->update(['is_active' => false]);

        return response()->json(['message' => 'Filter set deactivated']);
    }

    private function ensureOwner(Request $request, LeadFilterSet $leadFilterSet)
    {
        if ((int) $leadFilterSet->created_by !== (int) $request->user()?->id) {
            return response()->json(['message' => 'You can only manage your own saved filters.'], 403);
        }

        return null;
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
