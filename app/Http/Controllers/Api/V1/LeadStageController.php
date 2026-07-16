<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeadClosedReason;
use App\Models\LeadStage;
use App\Models\LeadStageAutomation;
use App\Models\LeadStagePermission;
use App\Models\LeadStageRequiredField;
use App\Models\LeadStageRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeadStageController extends Controller
{
    public function index(Request $request)
    {
        $stages = LeadStage::query()
            ->active()
            ->orderBy('order')
            ->with(['rulesFrom'])
            ->get()
            ->map(function ($stage) {
                return [
                    'id' => $stage->id,
                    'key' => $stage->key,
                    'label' => $stage->label,
                    'group' => $stage->group,
                    'type' => $stage->type,
                    'team' => $stage->team,
                    'order' => $stage->order,
                    'color' => $stage->color,
                    'is_terminal' => $stage->is_terminal,
                    'is_active' => $stage->is_active,
                    'description' => $stage->description,
                    'sla_hours' => $stage->sla_hours,
                    'legacy_status' => $stage->legacy_status,
                    'allowed_next' => $stage->rulesFrom->where('is_active', true)->pluck('to_stage_id')->values(),
                ];
            });

        $closedReasons = LeadClosedReason::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'stages' => $stages,
            'closed_reasons' => $closedReasons,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'key' => 'required|string|max:40|unique:lead_stages,key',
            'label' => 'required|string|max:80',
            'group' => 'required|in:active,inactive',
            'type' => 'required|in:open,won,lost',
            'team' => 'required|in:marketing,sales',
            'color' => 'nullable|string|max:16',
            'sla_hours' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'legacy_status' => 'nullable|string|max:40',
        ]);

        $maxOrder = LeadStage::max('order') ?? 0;
        $validated['order'] = $maxOrder + 1;
        $validated['is_active'] = true;

        $stage = LeadStage::create($validated);

        return response()->json($stage->load(['rulesFrom']), 201);
    }

    public function update(Request $request, LeadStage $leadStage)
    {
        $validated = $request->validate([
            'label' => 'sometimes|string|max:80',
            'team' => 'sometimes|in:marketing,sales',
            'color' => 'sometimes|nullable|string|max:16',
            'sla_hours' => 'sometimes|nullable|integer|min:0',
            'type' => 'sometimes|in:open,won,lost',
            'description' => 'sometimes|nullable|string',
            'legacy_status' => 'sometimes|nullable|string|max:40',
            'is_active' => 'sometimes|boolean',
            'allowed_next_ids' => 'sometimes|array',
            'allowed_next_ids.*' => 'integer|exists:lead_stages,id',
        ]);

        DB::transaction(function () use ($leadStage, $validated) {
            $leadStage->update($validated);

            if (isset($validated['allowed_next_ids'])) {
                LeadStageRule::where('from_stage_id', $leadStage->id)->delete();
                foreach ($validated['allowed_next_ids'] as $toId) {
                    LeadStageRule::create([
                        'from_stage_id' => $leadStage->id,
                        'to_stage_id' => $toId,
                        'is_active' => true,
                    ]);
                }
            }
        });

        return response()->json($leadStage->fresh(['rulesFrom']));
    }

    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'ordered_ids' => 'required|array',
            'ordered_ids.*' => 'integer|exists:lead_stages,id',
        ]);

        foreach ($validated['ordered_ids'] as $idx => $id) {
            LeadStage::where('id', $id)->update(['order' => $idx + 1]);
        }

        return response()->json(['message' => 'Stages reordered successfully']);
    }

    public function destroy(LeadStage $leadStage)
    {
        $leadStage->update(['is_active' => false]);
        return response()->json(['message' => 'Stage deactivated successfully']);
    }
}
