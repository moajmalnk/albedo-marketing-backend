<?php

namespace App\Services;

use App\Models\User;

class SalesCapsuleService
{
    public const CAPSULE_MEMBER_ROLES = ['psa', 'advisor'];

    public const TEAM_LEAD_ROLE = 'team_lead';

    /**
     * Active PSA/advisor IDs that report directly to this user (team lead capsule).
     *
     * @return list<int>
     */
    public function capsuleMemberIds(User $user): array
    {
        return User::query()
            ->where('reporting_manager_id', $user->id)
            ->where('status', 'active')
            ->whereHas('role', fn ($q) => $q->whereIn('key', self::CAPSULE_MEMBER_ROLES))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Sales-head subtree: team leads reporting to this user, plus PSA/advisors under those
     * team leads, plus PSA/advisors reporting directly to this sales head.
     *
     * @return list<int>
     */
    public function salesSubtreeMemberIds(User $user): array
    {
        $teamLeadIds = User::query()
            ->where('reporting_manager_id', $user->id)
            ->where('status', 'active')
            ->whereHas('role', fn ($q) => $q->where('key', self::TEAM_LEAD_ROLE))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $memberIds = User::query()
            ->where('status', 'active')
            ->whereHas('role', fn ($q) => $q->whereIn('key', self::CAPSULE_MEMBER_ROLES))
            ->where(function ($q) use ($user, $teamLeadIds) {
                $q->where('reporting_manager_id', $user->id);
                if ($teamLeadIds !== []) {
                    $q->orWhereIn('reporting_manager_id', $teamLeadIds);
                }
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique(array_merge($teamLeadIds, $memberIds)));
    }

    /**
     * IDs the actor may treat as assignable PSA/advisor owners.
     *
     * @return list<int>|null null means unrestricted (admin / sales_head / super_admin)
     */
    public function assignableOwnerIds(User $actor): ?array
    {
        $actor->loadMissing('role');
        $roleKey = $actor->role?->key ?? '';

        if (in_array($roleKey, ['super_admin', 'admin', 'sales_head'], true)) {
            return null;
        }

        if ($roleKey === self::TEAM_LEAD_ROLE) {
            return $this->capsuleMemberIds($actor);
        }

        return [];
    }

    /**
     * Whether the actor may manage the target user within sales capsule rules.
     * Role-level checks remain in UserController; this enforces capsule membership.
     */
    public function canManageCapsuleTarget(User $actor, User $target): bool
    {
        $actor->loadMissing('role');
        $target->loadMissing('role');
        $actorRole = $actor->role?->key ?? '';
        $targetRole = $target->role?->key ?? '';

        if (in_array($actorRole, ['super_admin', 'admin'], true)) {
            return true;
        }

        if ($actorRole === 'sales_head') {
            if ($targetRole === self::TEAM_LEAD_ROLE) {
                return true;
            }
            if (in_array($targetRole, self::CAPSULE_MEMBER_ROLES, true)) {
                return true;
            }

            return false;
        }

        if ($actorRole === self::TEAM_LEAD_ROLE) {
            if (! in_array($targetRole, self::CAPSULE_MEMBER_ROLES, true)) {
                return false;
            }

            return (int) $target->reporting_manager_id === (int) $actor->id;
        }

        return false;
    }

    /**
     * User IDs visible to the actor in team / staff listings for sales ops.
     *
     * @return list<int>|null null = no extra ID filter
     */
    public function visibleSalesStaffIds(User $actor): ?array
    {
        $actor->loadMissing('role');
        $roleKey = $actor->role?->key ?? '';

        if (in_array($roleKey, ['super_admin', 'admin', 'sales_head'], true)) {
            return null;
        }

        if ($roleKey === self::TEAM_LEAD_ROLE) {
            return $this->capsuleMemberIds($actor);
        }

        return [];
    }

    /**
     * Apply owner-scope to a leads query builder for team_lead (capsule owned + unassigned).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Lead>  $query
     */
    public function applyTeamLeadLeadScope($query, User $actor): void
    {
        $memberIds = $this->capsuleMemberIds($actor);

        $query->where(function ($q) use ($memberIds) {
            $q->whereNull('owner_id');
            if ($memberIds !== []) {
                $q->orWhereIn('owner_id', $memberIds)
                    ->orWhereIn('psa_owner_id', $memberIds)
                    ->orWhereIn('advisor_owner_id', $memberIds);
            }
        });
    }
}
