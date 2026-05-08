<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadStage;
use App\Models\LeadStageTransition;
use App\Services\LeadService;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    public function index(Request $request)
    {
        $query = Lead::query()->with(['stage', 'owner']);
        if ($request->filled('stage')) {
            $query->whereHas('stage', fn ($q) => $q->where('key', $request->string('stage')));
        }
        return response()->json($query->latest()->paginate(20));
    }

    public function store(Request $request, LeadService $leadService)
    {
        $data = $request->validate(['student_name' => ['required', 'string'], 'phone' => ['required', 'string']]);
        $data['created_by'] = $request->user()?->id;
        $data['stage_id'] = LeadStage::query()->where('key', 'new_lead')->value('id');
        return response()->json($leadService->createLead($data), 201);
    }

    public function show(Lead $lead)
    {
        return response()->json($lead->load(['stage', 'owner', 'activities']));
    }

    public function update(Request $request, Lead $lead)
    {
        if ($lead->is_read_only && ! $request->attributes->get('bypass_readonly')) {
            return response()->json(['message' => 'LEAD_IS_READ_ONLY'], 403);
        }
        $lead->update($request->all());
        return response()->json($lead->fresh());
    }

    public function assign(Request $request, Lead $lead)
    {
        $data = $request->validate(['owner_id' => ['required', 'integer']]);
        $lead->update(['owner_id' => $data['owner_id']]);
        return response()->json($lead->fresh());
    }

    public function changeStage(Request $request, Lead $lead)
    {
        $data = $request->validate(['stage_key' => ['required', 'string'], 'reason' => ['nullable', 'string']]);
        $targetStage = LeadStage::query()->where('key', $data['stage_key'])->firstOrFail();
        $linear = ['new_lead', 'prospect', 'assessment_booked', 'itb', 'enrolled'];
        $currentKey = $lead->stage?->key;
        if ($currentKey && in_array($currentKey, $linear, true)) {
            $currentIndex = array_search($currentKey, $linear, true);
            $targetIndex = array_search($targetStage->key, $linear, true);
            if ($targetIndex !== false && $targetIndex > $currentIndex + 1) {
                return response()->json(['message' => 'Invalid stage progression'], 422);
            }
        }
        $fromStageId = $lead->stage_id;
        $lead->update(['stage_id' => $targetStage->id, 'status' => $targetStage->label]);

        $transition = LeadStageTransition::query()->create([
            'lead_id' => $lead->id,
            'from_stage_id' => $fromStageId,
            'to_stage_id' => $targetStage->id,
            'reason' => $data['reason'] ?? null,
            'changed_by' => $request->user()->id,
            'changed_at' => now(),
        ]);

        return response()->json(['lead' => $lead->fresh('stage'), 'transition' => $transition], 200);
    }
}
