<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\UnknownCall;
use App\Models\User;
use App\Services\PhoneNormalizer;
use Illuminate\Http\Request;

class TelephonyWebhookController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'call_id' => ['nullable', 'string'],
            'direction' => ['nullable', 'string'],
            'from' => ['required', 'string'],
            'to' => ['nullable', 'string'],
            'agent_extension' => ['nullable', 'string'],
            'started_at' => ['nullable', 'date'],
            'duration_sec' => ['nullable', 'integer'],
            'recording_url' => ['nullable', 'string'],
            'disposition' => ['nullable', 'string'],
        ]);

        $phone = PhoneNormalizer::normalize($data['from']);
        $lead = Lead::query()->where('phone', $phone)->first();
        $agent = User::query()->where('phone_extension', $data['agent_extension'] ?? null)->first();

        if (! $lead) {
            UnknownCall::query()->create([
                'call_id' => $data['call_id'] ?? null,
                'direction' => $data['direction'] ?? null,
                'from_phone' => $phone,
                'to_phone' => $data['to'] ?? null,
                'agent_extension' => $data['agent_extension'] ?? null,
                'started_at' => $data['started_at'] ?? null,
                'duration_sec' => $data['duration_sec'] ?? null,
                'recording_url' => $data['recording_url'] ?? null,
                'disposition' => $data['disposition'] ?? null,
            ]);
            return response()->json(['status' => 'unknown_call_logged']);
        }

        LeadActivity::query()->create([
            'lead_id' => $lead->id,
            'user_id' => $agent?->id,
            'type' => 'call',
            'direction' => $data['direction'] ?? null,
            'connected' => ($data['disposition'] ?? '') === 'answered',
            'duration_sec' => $data['duration_sec'] ?? null,
            'recording_url' => $data['recording_url'] ?? null,
            'comments' => 'Telephony webhook event',
            'occurred_at' => $data['started_at'] ?? now(),
            'payload' => $data,
        ]);

        return response()->json(['status' => 'lead_call_logged']);
    }
}
