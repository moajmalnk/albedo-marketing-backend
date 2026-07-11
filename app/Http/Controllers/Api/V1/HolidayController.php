<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use Illuminate\Http\Request;

class HolidayController extends Controller
{
    public function index(Request $request)
    {
        $query = Holiday::query()->with('department');

        if ($request->has('year')) {
            $query->whereYear('date', $request->query('year'));
        }
        if ($request->has('month')) {
            $query->whereMonth('date', $request->query('month'));
        }

        $holidays = $query->orderBy('date')->get();
        return response()->json($holidays);
    }

    public function store(Request $request)
    {
        if (!auth()->user()->hasRole(['super_admin', 'admin'])) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'is_recurring' => ['required', 'boolean'],
            'department_id' => ['nullable', 'exists:departments,id'],
        ]);

        $holiday = Holiday::create($validated);
        return response()->json($holiday->load('department'), 201);
    }

    public function update(Request $request, Holiday $holiday)
    {
        if (!auth()->user()->hasRole(['super_admin', 'admin'])) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'is_recurring' => ['required', 'boolean'],
            'department_id' => ['nullable', 'exists:departments,id'],
        ]);

        $holiday->update($validated);
        return response()->json($holiday->load('department'));
    }

    public function destroy(Holiday $holiday)
    {
        if (!auth()->user()->hasRole(['super_admin', 'admin'])) {
            abort(403);
        }

        $holiday->delete();
        return response()->json(null, 204);
    }
}
