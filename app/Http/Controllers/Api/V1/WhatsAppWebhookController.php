<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadStage;
use App\Models\WhatsAppSession;
use App\Services\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WhatsAppWebhookController extends Controller
{
    public function workerSessions()
    {
        $sessions = WhatsAppSession::query()
            ->whereIn('status', ['PAIRING', 'CONNECTED'])
            ->orderBy('id')
            ->get(['id', 'user_id', 'session_name', 'status', 'phone_number', 'last_qr', 'last_sync', 'last_error']);

        return response()->json(['data' => $sessions]);
    }

    public function workerUpdateSession(Request $request, WhatsAppSession $whatsapp_session)
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['DISCONNECTED', 'PAIRING', 'CONNECTED', 'ERROR'])],
            'last_qr' => ['nullable', 'string'],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'last_error' => ['nullable', 'string'],
            'last_sync' => ['nullable', 'date'],
        ]);

        $whatsapp_session->update($data);

        return response()->json($whatsapp_session->fresh());
    }

    public function captureLead(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:40'],
            'pushname' => ['nullable', 'string', 'max:160'],
            'whatsapp_id' => ['required', 'string', 'max:64'],
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'session_name' => ['nullable', 'string', 'max:80'],
        ]);

        $phone = PhoneNormalizer::normalize($data['phone']);
        if ($phone === '') {
            return response()->json(['message' => 'INVALID_PHONE'], 422);
        }
        $ownerId = (int) $data['user_id'];

        $result = DB::transaction(function () use ($data, $phone, $ownerId, $request) {
            $lead = Lead::query()->where('whatsapp_id', $data['whatsapp_id'])->first()
                ?? Lead::query()->where('phone', $phone)->first();

            if ($lead) {
                $lead->update([
                    'last_contacted_at' => now(),
                    'whatsapp_id' => $data['whatsapp_id'],
                ]);

                LeadActivity::query()->create([
                    'lead_id' => $lead->id,
                    'user_id' => $ownerId,
                    'type' => 'whatsapp',
                    'direction' => 'inbound',
                    'connected' => true,
                    'comments' => 'WhatsApp inbound message (existing lead)',
                    'occurred_at' => now(),
                    'payload' => $request->all(),
                ]);

                return ['status' => 'updated', 'lead_id' => $lead->id, 'whatsapp_id' => $data['whatsapp_id']];
            }

            $stage = LeadStage::query()->where('key', 'new_lead')->first();

            $studentName = trim((string) ($data['pushname'] ?? '')) !== '' ? (string) $data['pushname'] : $phone;

            $lead = Lead::query()->create([
                'student_name' => $studentName,
                'phone' => $phone,
                'whatsapp' => $phone,
                'whatsapp_id' => $data['whatsapp_id'],
                'owner_id' => $ownerId,
                'captured_by_user_id' => $ownerId,
                'created_by' => $ownerId,
                'source_group' => 'other',
                'source_code' => 'whatsapp',
                'assigned_dept' => 'SALES',
                'stage_id' => $stage?->id,
                'status' => $stage?->label,
                'last_contacted_at' => now(),
            ]);

            LeadActivity::query()->create([
                'lead_id' => $lead->id,
                'user_id' => $ownerId,
                'type' => 'whatsapp',
                'direction' => 'inbound',
                'connected' => true,
                'comments' => 'WhatsApp lead captured',
                'occurred_at' => now(),
                'payload' => $request->all(),
            ]);

            return ['status' => 'created', 'lead_id' => $lead->id, 'whatsapp_id' => $data['whatsapp_id']];
        });

        $this->touchWhatsAppSession($ownerId, $sessionName);

        return response()->json($result);
    }

    private function touchWhatsAppSession(int $userId, string $sessionName): void
    {
        WhatsAppSession::query()
            ->where('user_id', $userId)
            ->where('session_name', $sessionName)
            ->update(['last_sync' => now()]);
    }
}
