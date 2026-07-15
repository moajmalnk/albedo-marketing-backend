<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserTarget;
use App\Services\TargetProgressService;
use Illuminate\Http\Request;

class UserTargetController extends Controller
{
    protected TargetProgressService $progressService;

    public function __construct(TargetProgressService $progressService)
    {
        $this->progressService = $progressService;
    }

    /**
     * Check if user has leadership role.
     */
    protected function authorizeLeadership(Request $request): void
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }
        $role = $user->role?->key;
        if (!in_array($role, ['super_admin', 'admin', 'dept_head', 'sales_head'], true)) {
            abort(403, 'UNAUTHORIZED_TARGET_ACCESS');
        }
    }

    public function index(Request $request)
    {
        $this->authorizeLeadership($request);

        $query = UserTarget::with(['user.role', 'user.departments']);

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->has('month')) {
            $query->where('month', $request->month);
        }
        if ($request->has('year')) {
            $query->where('year', $request->year);
        }

        $targets = $query->get();

        // Calculate achieved count dynamically for each target
        foreach ($targets as $target) {
            $target->achieved = $this->progressService->getAchievedCount($target);
            // Append temporary attributes for frontend backward compatibility
            $target->target = $target->target_value;
        }

        return response()->json($targets);
    }

    public function store(Request $request)
    {
        $this->authorizeLeadership($request);

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'target_type' => 'required|string|max:255',
            'period' => 'required|string|in:daily,weekly,monthly,quarterly,yearly',
            'target_value' => 'required|numeric|min:0',
            'product_name' => 'nullable|string|max:255',
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2000|max:2100',
        ]);

        $userTarget = UserTarget::updateOrCreate(
            [
                'user_id' => $validated['user_id'],
                'target_type' => $validated['target_type'],
                'period' => $validated['period'],
                'product_name' => $validated['product_name'] ?? null,
                'month' => $validated['month'] ?? null,
                'year' => $validated['year'] ?? null,
            ],
            [
                'target_value' => $validated['target_value'],
            ]
        );

        $userTarget->achieved = $this->progressService->getAchievedCount($userTarget);
        $userTarget->target = $userTarget->target_value;

        return response()->json($userTarget->load(['user.role', 'user.departments']), 201);
    }

    public function update(Request $request, UserTarget $userTarget)
    {
        $this->authorizeLeadership($request);

        $validated = $request->validate([
            'target_type' => 'sometimes|string|max:255',
            'period' => 'sometimes|string|in:daily,weekly,monthly,quarterly,yearly',
            'target_value' => 'sometimes|numeric|min:0',
            'product_name' => 'nullable|string|max:255',
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2000|max:2100',
        ]);

        $userTarget->update($validated);
        
        $userTarget->achieved = $this->progressService->getAchievedCount($userTarget);
        $userTarget->target = $userTarget->target_value;

        return response()->json($userTarget->load(['user.role', 'user.departments']));
    }

    public function destroy(UserTarget $userTarget)
    {
        $this->authorizeLeadership($request ?? request());

        $userTarget->delete();
        return response()->noContent();
    }

    /**
     * Get personal targets and dynamic achievements for the authenticated user.
     */
    public function myProgress(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $month = $request->query('month', (int) now()->month);
        $year = $request->query('year', (int) now()->year);

        $targets = UserTarget::where('user_id', $user->id)
            ->where('month', $month)
            ->where('year', $year)
            ->get();

        foreach ($targets as $target) {
            $target->achieved = $this->progressService->getAchievedCount($target);
            $target->target = $target->target_value;
        }

        return response()->json($targets);
    }
}
