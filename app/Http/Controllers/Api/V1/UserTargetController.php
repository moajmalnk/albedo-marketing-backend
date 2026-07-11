<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserTarget;
use Illuminate\Http\Request;

class UserTargetController extends Controller
{
    public function index(Request $request)
    {
        $query = UserTarget::with('user');

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->has('month')) {
            $query->where('month', $request->month);
        }
        if ($request->has('year')) {
            $query->where('year', $request->year);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'product_name' => 'required|string|max:255',
            'target' => 'required|integer|min:0',
            'achieved' => 'required|integer|min:0',
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2000|max:2100',
        ]);

        $userTarget = UserTarget::updateOrCreate(
            [
                'user_id' => $validated['user_id'],
                'product_name' => $validated['product_name'],
                'month' => $validated['month'] ?? null,
                'year' => $validated['year'] ?? null,
            ],
            [
                'target' => $validated['target'],
                'achieved' => $validated['achieved'],
            ]
        );

        return response()->json($userTarget->load('user'), 201);
    }

    public function update(Request $request, UserTarget $userTarget)
    {
        $validated = $request->validate([
            'product_name' => 'sometimes|string|max:255',
            'target' => 'sometimes|integer|min:0',
            'achieved' => 'sometimes|integer|min:0',
        ]);

        $userTarget->update($validated);
        return response()->json($userTarget->load('user'));
    }

    public function destroy(UserTarget $userTarget)
    {
        $userTarget->delete();
        return response()->noContent();
    }
}
