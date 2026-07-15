<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProductTarget;
use App\Models\Lead;
use App\Models\LeadStage;
use Illuminate\Http\Request;

class ProductTargetController extends Controller
{
    /**
     * Check authorization for product targets.
     */
    protected function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }
        $role = $user->role?->key;
        if (!in_array($role, ['super_admin', 'admin'], true)) {
            abort(403, 'UNAUTHORIZED_PRODUCT_TARGET_ACCESS');
        }
    }

    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $products = ProductTarget::all();
        $enrolledStageId = LeadStage::where('key', 'enrolled')->value('id');

        foreach ($products as $product) {
            $month = $product->month ?? (int) now()->month;
            $year = $product->year ?? (int) now()->year;

            // Calculate achieved leads count dynamically
            $achieved = Lead::where('course', $product->name)
                ->where('stage_id', $enrolledStageId)
                ->whereMonth('created_at', $month)
                ->whereYear('created_at', $year)
                ->count();

            $product->achieved = $achieved;
        }

        return response()->json($products);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'monthly_target' => 'required|integer|min:0',
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2000|max:2100',
            'status' => 'required|in:Active,Deactivated',
        ]);

        $productTarget = ProductTarget::create($validated);
        $productTarget->achieved = 0;

        return response()->json($productTarget, 201);
    }

    public function update(Request $request, ProductTarget $productTarget)
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'monthly_target' => 'sometimes|integer|min:0',
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2000|max:2100',
            'status' => 'sometimes|in:Active,Deactivated',
        ]);

        $productTarget->update($validated);

        $month = $productTarget->month ?? (int) now()->month;
        $year = $productTarget->year ?? (int) now()->year;
        $enrolledStageId = LeadStage::where('key', 'enrolled')->value('id');

        $productTarget->achieved = Lead::where('course', $productTarget->name)
            ->where('stage_id', $enrolledStageId)
            ->whereMonth('created_at', $month)
            ->whereYear('created_at', $year)
            ->count();

        return response()->json($productTarget);
    }

    public function destroy(ProductTarget $productTarget)
    {
        $this->authorizeAdmin($request ?? request());

        $productTarget->delete();
        return response()->noContent();
    }
}
