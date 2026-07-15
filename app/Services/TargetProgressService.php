<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadStage;
use App\Models\Payment;
use App\Models\UserTarget;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TargetProgressService
{
    /**
     * Calculate dynamic achievement value for a UserTarget.
     */
    public function getAchievedCount(UserTarget $target): float
    {
        $userId = $target->user_id;
        $type = $target->target_type;
        $period = $target->period ?? 'monthly';
        $month = $target->month ?? (int) now()->month;
        $year = $target->year ?? (int) now()->year;

        // Establish the time range query
        $queryBuilderCallback = function ($query) use ($period, $month, $year) {
            if ($period === 'daily') {
                $query->whereDate('created_at', Carbon::today()->toDateString());
            } elseif ($period === 'weekly') {
                $query->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]);
            } elseif ($period === 'yearly') {
                $query->whereYear('created_at', $year);
            } else { // default to monthly
                $query->whereMonth('created_at', $month)->whereYear('created_at', $year);
            }
        };

        // Cache stage IDs to speed up lookups
        $qualifiedStageId = LeadStage::where('key', 'qualified')->value('id');
        $enrolledStageId = LeadStage::where('key', 'enrolled')->value('id');
        $followUpStageId = LeadStage::where('key', 'follow_up')->value('id');
        $psaRecoveryStageId = LeadStage::where('key', 'psa_recovery')->value('id');
        $returnedStageId = LeadStage::where('key', 'returned_to_advisor')->value('id');

        switch ($type) {
            case 'leads_imported':
                $q = Lead::query()->where(function ($query) use ($userId) {
                    $query->where('generated_by_user_id', $userId)
                          ->orWhere('created_by', $userId);
                });
                $queryBuilderCallback($q);
                return (float) $q->count();

            case 'qualified_leads':
                $q = Lead::query()
                    ->where(function ($query) use ($userId) {
                        $query->where('owner_id', $userId)
                              ->orWhere('generated_by_user_id', $userId);
                    })
                    ->where('stage_id', $qualifiedStageId);
                $queryBuilderCallback($q);
                return (float) $q->count();

            case 'calls':
                $q = LeadActivity::query()
                    ->where('user_id', $userId)
                    ->where('type', 'call');
                $queryBuilderCallback($q);
                return (float) $q->count();

            case 'connected_calls':
                $q = LeadActivity::query()
                    ->where('user_id', $userId)
                    ->where('type', 'call')
                    ->where('connected', true);
                $queryBuilderCallback($q);
                return (float) $q->count();

            case 'follow_ups':
                $q = Lead::query()
                    ->where('owner_id', $userId)
                    ->where('stage_id', $followUpStageId);
                $queryBuilderCallback($q);
                return (float) $q->count();

            case 'assessments':
                // PSA recovery scheduled or performed screening
                $q = Lead::query()
                    ->where(function ($query) use ($userId) {
                        $query->where('owner_id', $userId)
                              ->orWhere('psa_owner_id', $userId);
                    })
                    ->where('stage_id', $psaRecoveryStageId);
                $queryBuilderCallback($q);
                return (float) $q->count();

            case 'admissions':
                $q = Lead::query()
                    ->where(function ($query) use ($userId) {
                        $query->where('owner_id', $userId)
                              ->orWhere('advisor_owner_id', $userId);
                    })
                    ->where('stage_id', $enrolledStageId);
                $queryBuilderCallback($q);
                return (float) $q->count();

            case 'revenue':
                // Sum payments matching lead ownership
                $q = Payment::query()
                    ->whereHas('lead', function ($query) use ($userId) {
                        $query->where('owner_id', $userId)
                              ->orWhere('advisor_owner_id', $userId);
                    });
                $queryBuilderCallback($q);
                return (float) $q->sum('amount');

            case 'recovery':
                // PSAs recovering leads back to advisor/enrolled
                $q = Lead::query()
                    ->where(function ($query) use ($userId) {
                        $query->where('owner_id', $userId)
                              ->orWhere('psa_owner_id', $userId);
                    })
                    ->whereIn('stage_id', [$returnedStageId, $enrolledStageId]);
                $queryBuilderCallback($q);
                return (float) $q->count();

            case 'recovery_revenue':
                $q = Payment::query()
                    ->whereHas('lead', function ($query) use ($userId, $returnedStageId, $enrolledStageId) {
                        $query->where(function ($sub) use ($userId) {
                            $sub->where('owner_id', $userId)
                                ->orWhere('psa_owner_id', $userId);
                        })->whereIn('stage_id', [$returnedStageId, $enrolledStageId]);
                    });
                $queryBuilderCallback($q);
                return (float) $q->sum('amount');

            default:
                return 0.0;
        }
    }
}
