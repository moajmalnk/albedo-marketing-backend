<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

class LeadService
{
    public function createLead(array $payload): Lead
    {
        $payload['phone'] = PhoneNormalizer::normalize($payload['phone'] ?? '');

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

        return Lead::query()->create($payload);
    }
}
