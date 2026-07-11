<?php

namespace App\Services;

use App\Models\Lead;

class DuplicateService
{
    public function findExistingLead(string $phone, ?string $email, string $criteria): ?Lead
    {
        $query = Lead::query();

        $normalizedPhone = '';
        try {
            $normalizedPhone = PhoneNormalizer::normalize($phone);
        } catch (\Throwable $e) {
            $normalizedPhone = $phone;
        }

        if ($criteria === 'phone') {
            return $query->where('phone', $normalizedPhone)->first();
        }

        if ($criteria === 'email') {
            if (empty($email)) {
                return null;
            }
            return $query->where('email', $email)->first();
        }

        // 'both' criteria (phone or email)
        return $query->where(function ($q) use ($normalizedPhone, $email) {
            $q->where('phone', $normalizedPhone);
            if (!empty($email)) {
                $q->orWhere('email', $email);
            }
        })->first();
    }

    public function processDuplicate(Lead $existingLead, array $data, string $strategy): void
    {
        if ($strategy === 'skip') {
            return;
        }

        if ($strategy === 'update') {
            $updateData = [];
            foreach ($data as $key => $value) {
                if ($value !== null && $value !== '') {
                    $updateData[$key] = $value;
                }
            }
            $existingLead->update($updateData);
            return;
        }

        if ($strategy === 'merge') {
            $mergeData = [];
            foreach ($data as $key => $value) {
                if ($value !== null && $value !== '') {
                    if (empty($existingLead->{$key})) {
                        $mergeData[$key] = $value;
                    }
                }
            }
            if (!empty($mergeData)) {
                $existingLead->update($mergeData);
            }
            return;
        }
    }
}
