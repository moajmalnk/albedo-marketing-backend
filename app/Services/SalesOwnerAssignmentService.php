<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SalesOwnerAssignmentService
{
    public const ALLOWED_OWNER_ROLES = ['advisor', 'psa'];

    public const ALLOWED_ACTOR_ROLES = ['sales_head', 'team_lead', 'admin', 'super_admin'];

    /**
     * @param  list<int>  $leadIds
     * @return Collection<int, Lead>
     */
    public function assignMany(array $leadIds, int $ownerId, ?User $actor = null, ?string $reason = null): Collection
    {
        $this->assertActorCanAssign($actor);

        $owner = User::query()->with('role')->findOrFail($ownerId);
        $ownerRole = $owner->role?->key;

        if (! in_array($ownerRole, self::ALLOWED_OWNER_ROLES, true)) {
            throw new InvalidArgumentException('Owner must be an active advisor or PSA.');
        }

        if (($owner->status ?? null) !== null && strtolower((string) $owner->status) !== 'active') {
            throw new InvalidArgumentException('Owner must be an active advisor or PSA.');
        }

        if ($actor) {
            $allowedOwnerIds = app(SalesCapsuleService::class)->assignableOwnerIds($actor);
            if (is_array($allowedOwnerIds) && ! in_array((int) $owner->id, $allowedOwnerIds, true)) {
                throw new InvalidArgumentException('Owner is outside your team capsule.');
            }
        }

        $notes = $reason ?: ($ownerRole === 'advisor'
            ? 'Assigned advisor'
            : 'Assigned PSA');

        return DB::transaction(function () use ($leadIds, $owner, $ownerRole, $actor, $notes) {
            $leads = Lead::query()->whereIn('id', $leadIds)->get();
            $updated = collect();

            foreach ($leads as $lead) {
                $updated->push($this->assignOne($lead, $owner, $ownerRole, $actor, $notes));
            }

            return $updated;
        });
    }

    public function assignOne(
        Lead $lead,
        User $owner,
        string $ownerRole,
        ?User $actor = null,
        ?string $reason = null
    ): Lead {
        $previousOwnerId = $lead->owner_id;
        $isReassign = $previousOwnerId !== null;
        $notes = $reason ?: ($isReassign ? 'Sales Reassignment' : 'Initial Sales Assignment');

        $lead->assignment_type = $isReassign ? 'Sales Reassignment' : 'Initial Sales Assignment';
        $lead->assignment_reason = $notes;

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
        ];

        $lead->update($payload);

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
                'stage_id' => $lead->stage_id,
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
            throw new InvalidArgumentException('Only sales heads, team leads, and admins can assign advisor/PSA owners.');
        }
    }
}
