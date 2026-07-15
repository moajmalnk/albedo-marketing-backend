<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadStage;
use App\Models\LeadStageTransition;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SalesOwnerAssignmentService
{
    public const ALLOWED_OWNER_ROLES = ['advisor', 'psa'];

    public const ALLOWED_ACTOR_ROLES = ['sales_head', 'admin', 'super_admin'];

    /**
     * @param  list<int>  $leadIds
     * @return Collection<int, Lead>
     */
    public function assignMany(array $leadIds, int $ownerId, ?User $actor = null, ?string $reason = null): Collection
    {
        $owner = User::query()->with('role')->findOrFail($ownerId);
        $ownerRole = $owner->role?->key;

        if (! in_array($ownerRole, self::ALLOWED_OWNER_ROLES, true)) {
            throw new InvalidArgumentException('Owner must be an active advisor or PSA.');
        }

        if (($owner->status ?? null) !== null && strtolower((string) $owner->status) !== 'active') {
            throw new InvalidArgumentException('Owner must be an active advisor or PSA.');
        }

        $stageKey = $ownerRole === 'advisor' ? 'advisor_counselling' : 'psa_recovery';
        $stage = LeadStage::query()->where('key', $stageKey)->first();
        if (! $stage) {
            throw new InvalidArgumentException("Required stage '{$stageKey}' is not configured.");
        }

        $notes = $reason ?: ($ownerRole === 'advisor'
            ? 'Sales head assigned advisor'
            : 'Sales head assigned PSA');

        return DB::transaction(function () use ($leadIds, $owner, $ownerRole, $stage, $actor, $notes) {
            $leads = Lead::query()->whereIn('id', $leadIds)->get();
            $updated = collect();

            foreach ($leads as $lead) {
                $updated->push($this->assignOne($lead, $owner, $ownerRole, $stage, $actor, $notes));
            }

            return $updated;
        });
    }

    public function assignOne(
        Lead $lead,
        User $owner,
        string $ownerRole,
        LeadStage $stage,
        ?User $actor = null,
        ?string $reason = null
    ): Lead {
        $previousOwnerId = $lead->owner_id;
        $isReassign = $previousOwnerId !== null;
        $notes = $reason ?: ($isReassign ? 'Sales Reassignment' : 'Initial Sales Assignment');

        $lead->assignment_type = $isReassign ? 'Sales Reassignment' : 'Initial Sales Assignment';
        $lead->assignment_reason = $notes;

        $fromStageId = $lead->stage_id;

        $payload = [
            'owner_id' => $owner->id,
            'advisor_owner_id' => $ownerRole === 'advisor' ? $owner->id : null,
            'psa_owner_id' => $ownerRole === 'psa' ? $owner->id : null,
            // Preserve telecaller_owner_id — original telecaller stays on the lead
            'assignment_status' => 'assigned',
            'assigned_by' => $actor?->id ?? auth()->id(),
            'assigned_at' => now(),
            'assignment_notes' => $notes,
            'routing_failed' => false,
            'assigned_dept' => 'SALES',
            'stage_id' => $stage->id,
        ];

        $lead->update($payload);

        if ($fromStageId !== $stage->id) {
            LeadStageTransition::create([
                'lead_id' => $lead->id,
                'from_stage_id' => $fromStageId,
                'to_stage_id' => $stage->id,
                'reason' => $notes,
                'changed_by' => $actor?->id ?? auth()->id(),
                'changed_at' => now(),
            ]);
        }

        LeadActivity::query()->create([
            'lead_id' => $lead->id,
            'user_id' => $actor?->id ?? auth()->id(),
            'type' => 'assignment',
            'outcome' => $isReassign
                ? ($ownerRole === 'advisor' ? 'Reassigned to Advisor' : 'Reassigned to PSA')
                : ($ownerRole === 'advisor' ? 'Assigned to Advisor' : 'Assigned to PSA'),
            'comments' => $notes,
            'payload' => [
                'previous_owner_id' => $previousOwnerId,
                'new_owner_id' => $owner->id,
                'owner_role' => $ownerRole,
                'owner_name' => trim(implode(' ', array_filter([$owner->first_name, $owner->last_name]))) ?: $owner->email,
                'stage_key' => $stage->key,
                'is_reassign' => $isReassign,
            ],
            'occurred_at' => now(),
        ]);

        return $lead->fresh(['owner.role', 'stage', 'telecallerOwner', 'psaOwner', 'advisorOwner']);
    }

    public function assertActorCanAssign(?User $actor): void
    {
        $roleKey = $actor?->role?->key;
        if (! in_array($roleKey, self::ALLOWED_ACTOR_ROLES, true)) {
            throw new InvalidArgumentException('Only sales heads and admins can assign advisor/PSA owners.');
        }
    }
}
