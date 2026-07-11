<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaveTypeController extends Controller
{
    public function index()
    {
        $types = LeaveType::orderBy('name')->get();
        return response()->json($types);
    }

    public function store(Request $request)
    {
        if (!auth()->user()->hasRole(['super_admin', 'admin'])) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:leave_types,name'],
            'days_allowed_per_year' => ['required', 'integer', 'min:0'],
            'is_paid' => ['required', 'boolean'],
            'color' => ['nullable', 'string', 'max:50'],
        ]);

        $type = LeaveType::create($validated);
        return response()->json($type, 201);
    }

    public function update(Request $request, LeaveType $leaveType)
    {
        if (!auth()->user()->hasRole(['super_admin', 'admin'])) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('leave_types')->ignore($leaveType->id)],
            'days_allowed_per_year' => ['required', 'integer', 'min:0'],
            'is_paid' => ['required', 'boolean'],
            'color' => ['nullable', 'string', 'max:50'],
        ]);

        $leaveType->update($validated);
        return response()->json($leaveType);
    }

    public function destroy(LeaveType $leaveType)
    {
        if (!auth()->user()->hasRole(['super_admin', 'admin'])) {
            abort(403);
        }

        $leaveType->delete();
        return response()->json(null, 204);
    }
}
