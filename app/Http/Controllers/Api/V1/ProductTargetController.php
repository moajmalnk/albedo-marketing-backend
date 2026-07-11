<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProductTarget;
use Illuminate\Http\Request;

class ProductTargetController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(ProductTarget::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'monthly_target' => 'required|integer|min:0',
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2000|max:2100',
            'status' => 'required|in:Active,Deactivated',
        ]);

        $productTarget = ProductTarget::create($validated);
        return response()->json($productTarget, 201);
    }

    public function update(Request $request, ProductTarget $productTarget)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'monthly_target' => 'sometimes|integer|min:0',
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2000|max:2100',
            'status' => 'sometimes|in:Active,Deactivated',
        ]);

        $productTarget->update($validated);
        return response()->json($productTarget);
    }

    public function destroy(ProductTarget $productTarget)
    {
        $productTarget->delete();
        return response()->noContent();
    }
}
