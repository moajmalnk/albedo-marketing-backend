<?php

namespace App\Http\Controllers;

use App\Models\LeadSource;
use Illuminate\Http\Request;

class LeadSourceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return LeadSource::orderBy('created_at', 'desc')->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:lead_sources',
            'type' => 'required|string',
            'category' => 'required|string|in:performance marketing,influence marketing,other',
            'status' => 'required|string|in:Active,Inactive',
        ]);

        return LeadSource::create($validated);
    }

    public function update(Request $request, LeadSource $leadSource)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:lead_sources,name,' . $leadSource->id,
            'type' => 'required|string',
            'category' => 'required|string|in:performance marketing,influence marketing,other',
            'status' => 'required|string|in:Active,Inactive',
        ]);

        $leadSource->update($validated);
        return $leadSource;
    }

    public function destroy(LeadSource $leadSource)
    {
        // TODO: Could check for existing leads before deleting
        $leadSource->delete();
        return response()->noContent();
    }
}
