<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SalesAnalyticsReport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SalesAnalyticsReportController extends Controller
{
    public function index(Request $request)
    {
        $this->assertAccess($request);

        $user = $request->user();
        $roleKey = $user?->role?->key;

        $query = SalesAnalyticsReport::query()->orderByDesc('updated_at');

        if ($roleKey !== 'super_admin') {
            $query->where('owner_id', $user->id);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $this->assertAccess($request);

        $ownerId = (int) $request->user()->id;

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('sales_analytics_reports', 'name')->where(fn ($q) => $q->where('owner_id', $ownerId)),
            ],
            'config' => ['required', 'array'],
            'config.rows' => ['nullable', 'array', 'max:5'],
            'config.columns' => ['nullable', 'array', 'max:1'],
            'config.values' => ['nullable', 'array', 'max:25'],
            'config.filters' => ['nullable', 'array'],
        ], [
            'name.unique' => 'You already have a report with this name. Choose a different name.',
        ]);

        $report = SalesAnalyticsReport::create([
            'name' => $validated['name'],
            'owner_id' => $ownerId,
            'config' => $validated['config'],
        ]);

        return response()->json($report, 201);
    }

    public function update(Request $request, SalesAnalyticsReport $salesAnalyticsReport)
    {
        $this->assertAccess($request);
        $this->assertOwns($request, $salesAnalyticsReport);

        $ownerId = (int) $salesAnalyticsReport->owner_id;

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:120',
                Rule::unique('sales_analytics_reports', 'name')
                    ->where(fn ($q) => $q->where('owner_id', $ownerId))
                    ->ignore($salesAnalyticsReport->id),
            ],
            'config' => ['sometimes', 'array'],
            'config.rows' => ['nullable', 'array', 'max:5'],
            'config.columns' => ['nullable', 'array', 'max:1'],
            'config.values' => ['nullable', 'array', 'max:25'],
            'config.filters' => ['nullable', 'array'],
        ], [
            'name.unique' => 'You already have a report with this name. Choose a different name.',
        ]);

        $salesAnalyticsReport->update($validated);

        return response()->json($salesAnalyticsReport->fresh());
    }

    public function destroy(Request $request, SalesAnalyticsReport $salesAnalyticsReport)
    {
        $this->assertAccess($request);
        $this->assertOwns($request, $salesAnalyticsReport);

        $salesAnalyticsReport->delete();

        return response()->json(['message' => 'Report deleted']);
    }

    private function assertAccess(Request $request): void
    {
        $request->user()?->loadMissing('role');
        $key = $request->user()?->role?->key;
        if (! in_array($key, ['super_admin', 'sales_head'], true)) {
            abort(403, 'Only Super Admin and Sales Head can manage sales analytics reports.');
        }
    }

    private function assertOwns(Request $request, SalesAnalyticsReport $report): void
    {
        $user = $request->user();
        $roleKey = $user?->role?->key;
        if ($roleKey === 'super_admin') {
            return;
        }
        if ((int) $report->owner_id !== (int) $user->id) {
            abort(403, 'You can only modify your own reports.');
        }
    }
}
