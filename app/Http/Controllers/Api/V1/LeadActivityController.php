<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Lead;
use App\Models\LeadActivity;
use Illuminate\Http\Request;

class LeadActivityController extends Controller
{
    public function index(Lead $lead)
    {
        return response()->json($lead->activities()->latest('occurred_at')->get());
    }

    public function store(Request $request, Lead $lead)
    {
        $data = $request->validate([
            'type' => ['required', 'string'],
            'direction' => ['nullable', 'string'],
            'connected' => ['nullable', 'boolean'],
            'outcome' => ['nullable', 'string'],
            'comments' => ['nullable', 'string'],
            'duration_sec' => ['nullable', 'integer'],
            'assessment' => ['nullable', 'array'],
        ]);

        $activity = LeadActivity::query()->create([
            ...$data,
            'lead_id' => $lead->id,
            'user_id' => $request->user()->id,
            'occurred_at' => now(),
            'payload' => $data['assessment'] ?? null,
        ]);

        if (($data['outcome'] ?? null) === 'Assessment Booked' && ! empty($data['assessment'])) {
            Assessment::query()->create([
                'lead_id' => $lead->id,
                'activity_id' => $activity->id,
                ...$data['assessment'],
                'status' => 'booked',
            ]);
        }

        return response()->json($activity, 201);
    }
}
