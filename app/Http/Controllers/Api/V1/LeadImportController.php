<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadStage;
use App\Services\LeadService;
use App\Services\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LeadImportController extends Controller
{
    public function store(Request $request, LeadService $leadService)
    {
        $rows = $request->validate(['rows' => ['required', 'array', 'max:1000']])['rows'];
        $newLeadStageId = LeadStage::query()->where('key', 'new_lead')->value('id');
        $result = [];

        foreach ($rows as $index => $row) {
            try {
                $phone = PhoneNormalizer::normalize((string) ($row['phone'] ?? ''));
                if (Lead::query()->where('phone', $phone)->exists()) {
                    $result[] = ['row' => $index + 1, 'status' => 'conflict', 'phone' => $phone];
                    continue;
                }

                $leadService->createLead([
                    'student_name' => $row['student_name'] ?? 'Unknown',
                    'phone' => $phone,
                    'source_group' => $row['source_group'] ?? 'other',
                    'source_code' => $row['source_code'] ?? 'import',
                    'created_by' => $request->user()->id,
                    'stage_id' => $newLeadStageId,
                ]);
                $result[] = ['row' => $index + 1, 'status' => 'created'];
            } catch (ValidationException $e) {
                $result[] = ['row' => $index + 1, 'status' => 'invalid', 'errors' => $e->errors()];
            }
        }

        return response()->json(['results' => $result]);
    }
}
