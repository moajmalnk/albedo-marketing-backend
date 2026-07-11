<?php

namespace Database\Seeders;

use App\Models\LeadClosedReason;
use App\Models\LeadStage;
use App\Models\LeadStagePermission;
use App\Models\LeadStageRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LeadStageEngineSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        LeadStageRule::truncate();
        LeadStagePermission::truncate();
        LeadStage::truncate();
        LeadClosedReason::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $stages = [
            [
                'id' => 1,
                'key' => 'new_lead',
                'label' => 'New Lead',
                'group' => 'active',
                'type' => 'open',
                'order' => 1,
                'color' => '#3b82f6',
                'is_terminal' => false,
                'is_active' => true,
                'owner_role' => 'marketer',
                'sla_hours' => 4,
                'allowed_next' => [2, 10], // Assign to telecaller, or lose immediately
            ],
            [
                'id' => 2,
                'key' => 'assigned_telecaller',
                'label' => 'Assigned to Telecaller',
                'group' => 'active',
                'type' => 'open',
                'order' => 2,
                'color' => '#06b6d4',
                'is_terminal' => false,
                'is_active' => true,
                'owner_role' => 'telecaller',
                'sla_hours' => 24,
                'allowed_next' => [3, 10], // Qualify or Lose
            ],
            [
                'id' => 3,
                'key' => 'qualified',
                'label' => 'Qualified',
                'group' => 'active',
                'type' => 'open',
                'order' => 3,
                'color' => '#10b981',
                'is_terminal' => false,
                'is_active' => true,
                'owner_role' => 'telecaller',
                'sla_hours' => 12,
                'allowed_next' => [4, 10],
            ],
            [
                'id' => 4,
                'key' => 'sales_head_review',
                'label' => 'Sales Head Review',
                'group' => 'active',
                'type' => 'open',
                'order' => 4,
                'color' => '#8b5cf6',
                'is_terminal' => false,
                'is_active' => true,
                'owner_role' => 'sales_head',
                'sla_hours' => 12,
                'allowed_next' => [5, 6, 10], // Proceed to advisor OR recovery OR lost
            ],
            [
                'id' => 5,
                'key' => 'advisor_counselling',
                'label' => 'Advisor Counselling',
                'group' => 'active',
                'type' => 'open',
                'order' => 5,
                'color' => '#ec4899',
                'is_terminal' => false,
                'is_active' => true,
                'owner_role' => 'advisor',
                'sla_hours' => 48,
                'allowed_next' => [9, 6, 10], // Enroll OR need recovery OR lost
            ],
            [
                'id' => 6,
                'key' => 'recovery_required',
                'label' => 'Recovery Required',
                'group' => 'active',
                'type' => 'open',
                'order' => 6,
                'color' => '#f97316',
                'is_terminal' => false,
                'is_active' => true,
                'owner_role' => 'sales_head',
                'sla_hours' => 12,
                'allowed_next' => [7, 10], // PSA Recovery or lost
            ],
            [
                'id' => 7,
                'key' => 'psa_recovery',
                'label' => 'PSA Recovery',
                'group' => 'active',
                'type' => 'open',
                'order' => 7,
                'color' => '#a855f7',
                'is_terminal' => false,
                'is_active' => true,
                'owner_role' => 'psa',
                'sla_hours' => 48,
                'allowed_next' => [8, 10], // Return to Advisor or Lost
            ],
            [
                'id' => 8,
                'key' => 'returned_to_advisor',
                'label' => 'Return to Advisor',
                'group' => 'active',
                'type' => 'open',
                'order' => 8,
                'color' => '#6366f1',
                'is_terminal' => false,
                'is_active' => true,
                'owner_role' => 'advisor',
                'sla_hours' => 24,
                'allowed_next' => [9, 10], // Enrolled or Lost
            ],
            [
                'id' => 9,
                'key' => 'enrolled',
                'label' => 'Enrolled',
                'group' => 'active',
                'type' => 'won',
                'order' => 9,
                'color' => '#22c55e',
                'is_terminal' => true,
                'is_active' => true,
                'owner_role' => 'advisor',
                'sla_hours' => 0,
                'allowed_next' => [],
            ],
            [
                'id' => 10,
                'key' => 'closed_lost',
                'label' => 'Lost',
                'group' => 'active',
                'type' => 'lost',
                'order' => 10,
                'color' => '#ef4444',
                'is_terminal' => true,
                'is_active' => true,
                'owner_role' => 'advisor',
                'sla_hours' => 0,
                'allowed_next' => [],
            ],
        ];

        // First pass: Create stages
        foreach ($stages as $stageData) {
            $allowedNext = $stageData['allowed_next'];
            unset($stageData['allowed_next']);
            $stage = LeadStage::create($stageData);

            // Default permissions for all core roles
            $roles = ['super_admin', 'admin', 'sales_head', 'department_head', 'telecaller', 'psa', 'advisor', 'marketer'];
            foreach ($roles as $role) {
                $isSuperOrHead = in_array($role, ['super_admin', 'admin', 'sales_head', 'department_head'], true);
                $isOwnerRole = ($stage->owner_role === $role);
                
                LeadStagePermission::create([
                    'lead_stage_id' => $stage->id,
                    'role' => $role,
                    'can_view' => true,
                    'can_move' => $isSuperOrHead || $isOwnerRole,
                    'can_override' => $isSuperOrHead,
                    'can_close' => $isSuperOrHead || $isOwnerRole,
                    'can_reopen' => $isSuperOrHead,
                    'can_delete' => in_array($role, ['super_admin', 'admin'], true),
                ]);
            }
        }

        // Second pass: Create transitions
        foreach ($stages as $stageData) {
            $allowedNext = $stageData['allowed_next'];
            foreach ($allowedNext as $nextId) {
                LeadStageRule::create([
                    'from_stage_id' => $stageData['id'],
                    'to_stage_id' => $nextId,
                    'is_active' => true,
                ]);
            }
        }

        $closedReasons = [
            ['id' => 1, 'key' => 'wrong_number', 'label' => 'Wrong Number', 'color' => '#6b7280', 'is_active' => true, 'sort_order' => 1],
            ['id' => 2, 'key' => 'duplicate', 'label' => 'Duplicate Lead', 'color' => '#4b5563', 'is_active' => true, 'sort_order' => 2],
            ['id' => 3, 'key' => 'fake_lead', 'label' => 'Fake Lead / Invalid', 'color' => '#991b1b', 'is_active' => true, 'sort_order' => 3],
            ['id' => 4, 'key' => 'budget_issue', 'label' => 'Budget Issue', 'color' => '#f97316', 'is_active' => true, 'sort_order' => 4],
            ['id' => 5, 'key' => 'joined_competitor', 'label' => 'Joined Competitor', 'color' => '#ef4444', 'is_active' => true, 'sort_order' => 5],
            ['id' => 6, 'key' => 'not_interested', 'label' => 'Not Interested', 'color' => '#6b7280', 'is_active' => true, 'sort_order' => 6],
            ['id' => 7, 'key' => 'no_response', 'label' => 'No Response (NATC)', 'color' => '#78716c', 'is_active' => true, 'sort_order' => 7],
            ['id' => 8, 'key' => 'parent_rejected', 'label' => 'Parent Rejected', 'color' => '#b91c1c', 'is_active' => true, 'sort_order' => 8],
        ];

        foreach ($closedReasons as $cr) {
            LeadClosedReason::create($cr);
        }
    }
}
