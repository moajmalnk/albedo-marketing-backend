<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\LeadHistoryService;
use Illuminate\Http\JsonResponse;

class LeadHistoryController extends Controller
{
    public function __construct(
        private readonly LeadHistoryService $leadHistoryService,
    ) {}

    /**
     * Unified timeline: audit trail (creates/updates/stage) + CRM activities.
     */
    public function index(Lead $lead): JsonResponse
    {
        return response()->json($this->leadHistoryService->timeline($lead));
    }
}
