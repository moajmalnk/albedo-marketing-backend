<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

class LeadService
{
    public function createLead(array $payload): Lead
    {
        $payload['phone'] = PhoneNormalizer::normalize($payload['phone'] ?? '');
        if (! empty($payload['alternate_phone'])) {
            $payload['alternate_phone'] = PhoneNormalizer::normalize((string) $payload['alternate_phone']);
        }
        if (! empty($payload['whatsapp'])) {
            $payload['whatsapp'] = PhoneNormalizer::normalize((string) $payload['whatsapp']);
        }

        if (! isset($payload['generated_by_user_id']) && isset($payload['created_by'])) {
            $payload['generated_by_user_id'] = $payload['created_by'];
        }

        $existing = Lead::query()->with(['owner', 'stage'])->where('phone', $payload['phone'])->first();
        if ($existing) {
            throw new HttpResponseException(
                response()->json([
                    'message' => 'LEAD_ALREADY_EXISTS',
                    'phone' => $payload['phone'],
                    'existing' => [
                        'id' => $existing->id,
                        'owner' => $existing->owner ? [
                            'id' => $existing->owner->id,
                            'name' => trim($existing->owner->first_name.' '.$existing->owner->last_name),
                        ] : null,
                        'stage' => $existing->stage ? [
                            'id' => $existing->stage->id,
                            'key' => $existing->stage->key,
                            'label' => $existing->stage->label,
                        ] : null,
                    ],
                ], 409)
            );
        }

        $lead = Lead::query()->create($payload);
        $lead->update([
            'assignment_status' => 'waiting',
            'owner_id' => null,
            'routing_failed' => false,
        ]);

        return $lead;
    }
}
