<?php

namespace App\Policies;

use App\Models\User;
use App\Models\MarketingChallenge;

class ChallengePolicy
{
    private function checkAdmin(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isAdmin();
    }

    private function getDepartmentName(User $user): ?string
    {
        // Challenge departments match active department names.
        // Let's resolve the user's department.
        $dept = $user->department;
        if ($dept === 'PM') {
            return 'Performance Marketing';
        }
        if ($dept === 'IM') {
            return 'Influence Marketing';
        }
        if ($dept === 'SALES') {
            return 'Sales';
        }
        if ($dept === 'OPS') {
            return 'Operations';
        }
        return $dept;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MarketingChallenge $challenge): bool
    {
        if ($this->checkAdmin($user)) {
            return true;
        }

        if ($challenge->assigned_to === $user->id || $challenge->created_by === $user->id) {
            return true;
        }

        // Must be in the same department
        $userDept = $this->getDepartmentName($user);
        return $userDept && $challenge->department === $userDept;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, MarketingChallenge $challenge): bool
    {
        if ($this->checkAdmin($user)) {
            return true;
        }

        if ($challenge->created_by === $user->id) {
            return true;
        }

        if ($challenge->assigned_to === $user->id) {
            return true;
        }

        // Must be the department head of the same department
        if ($user->isDepartmentHead()) {
            $userDept = $this->getDepartmentName($user);
            return $userDept && $challenge->department === $userDept;
        }

        return false;
    }

    public function delete(User $user, MarketingChallenge $challenge): bool
    {
        return $this->checkAdmin($user);
    }
}
