<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadStage;
use App\Models\LeadStageTransition;
use App\Services\LeadService;
use Database\Seeders\LeadStageSeeder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    public function index(Request $request)
    {
        $query = Lead::query()->with(['stage', 'owner']);
        if ($request->filled('stage')) {
            $query->whereHas('stage', fn ($q) => $q->where('key', $request->string('stage')));
        }
        if ($request->filled('source_code')) {
            $query->where('source_code', $request->string('source_code'));
        }
        if ($request->filled('owner_id')) {
            $query->where('owner_id', (int) $request->input('owner_id'));
        }

        $sort = (string) $request->input('sort', '-created_at');
        if ($sort === '-created_at') {
            $query->orderByDesc('created_at')->orderByDesc('id');
        } elseif ($sort === 'created_at') {
            $query->orderBy('created_at')->orderBy('id');
        } else {
            $query->latest('id');
        }

        $perPage = (int) $request->input('limit', 20);
        $perPage = max(1, min(50, $perPage));

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request, LeadService $leadService)
    {
        $data = $request->validate([
            'student_name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:20'],
            'whatsapp' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'string', 'email', 'max:160'],
            'parent_name' => ['nullable', 'string', 'max:160'],
            'parent_relation' => ['nullable', 'string', Rule::in(['father', 'mother', 'guardian'])],
            'class' => ['nullable', 'string', 'max:20'],
            'syllabus' => ['nullable', 'string', Rule::in(['STATE', 'CBSE', 'ICSE', 'IGCSE', 'IB'])],
            'course' => ['nullable', 'string', Rule::in(['Foundation', 'Academics', 'Crash', 'Repeater', 'Other'])],
            'subjects' => ['nullable', 'array'],
            'subjects.*' => ['string', 'max:120'],
            'school' => ['nullable', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:80'],
            'district' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'max:80'],
            'pincode' => ['nullable', 'string', 'max:12'],
            'source_group' => ['nullable', 'string', Rule::in(['influence', 'performance', 'albedo', 'reference', 'other'])],
            'source_code' => ['nullable', 'string', 'max:40'],
            'campaign' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:40'],
            'owner_id' => ['nullable', 'integer'],
            'assigned_dept' => ['nullable', 'string', Rule::in(['SALES', 'MARKETING'])],
            'priority' => ['nullable', 'string', Rule::in(['low', 'normal', 'high'])],
            'dnd' => ['nullable', 'boolean'],
            'next_action_at' => ['nullable', 'date'],
        ]);

        foreach ($data as $key => $value) {
            if ($value === '' && ! in_array($key, ['student_name', 'phone'], true)) {
                unset($data[$key]);
            }
        }

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
        $targetStage = LeadStage::query()->where('key', $data['stage_key'])->first();
        if (! $targetStage) {
            // Production DBs often predate the expanded pipeline; upsert once then retry.
            (new LeadStageSeeder)->run();
            $targetStage = LeadStage::query()->where('key', $data['stage_key'])->first();
        }
        if (! $targetStage) {
            return response()->json([
                'message' => 'Unknown stage_key',
                'stage_key' => $data['stage_key'],
            ], 422);
        }
        $linear = [
            'new_lead',
            'prospect',
            'demo_required',
            'itb',
            'follow_up',
            'dnp',
            'assessment_booked',
            'assessment_done',
            'enrolled',
        ];
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
