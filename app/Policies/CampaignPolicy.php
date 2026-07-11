<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Campaign;

class CampaignPolicy
{
    private function checkRole(User $user, array $roles): bool
    {
        $roleKey = $user->role?->key;
        return in_array($roleKey, $roles, true);
    }

    public function viewAny(User $user): bool
    {
        return $this->checkRole($user, ['super_admin', 'admin', 'dept_head', 'department_head', 'marketing_head', 'marketer', 'sales_head']);
    }

    public function view(User $user, Campaign $campaign): bool
    {
        return $this->checkRole($user, ['super_admin', 'admin', 'dept_head', 'department_head', 'marketing_head', 'marketer', 'sales_head']);
    }

    public function create(User $user): bool
    {
        return $this->checkRole($user, ['super_admin', 'admin', 'marketing_head']);
    }

    public function update(User $user, Campaign $campaign): bool
    {
        return $this->checkRole($user, ['super_admin', 'admin', 'marketing_head']);
    }

    public function delete(User $user, Campaign $campaign): bool
    {
        return $this->checkRole($user, ['super_admin', 'admin', 'marketing_head']);
    }

    public function archive(User $user, Campaign $campaign): bool
    {
        return $this->checkRole($user, ['super_admin', 'admin', 'marketing_head']);
    }
}
