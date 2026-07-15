<?php

namespace Database\Seeders;

use App\Models\LeadClosedReason;
use App\Models\LeadStage;
use App\Models\LeadStageAutomation;
use App\Models\LeadStagePermission;
use App\Models\LeadStageRequiredField;
use App\Models\LeadStageRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class LeadStageSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Pipeline Stages (Active funnel left → right based on HR 10-Stage Spec)
        $stages = [
            [
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
                'legacy_status' => 'New',
                'description' => 'Newly imported or captured lead awaiting telecaller assignment.',
            ],
            [
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
                'legacy_status' => 'Assigned',
                'description' => 'Lead assigned to Telecaller for initial contact and qualification.',
            ],
            [
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
                'legacy_status' => 'Qualified',
                'description' => 'Telecaller verified genuine interest and submitted qualification details.',
            ],
            [
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
                'legacy_status' => 'Sales Head Review',
                'description' => 'Sales Head reviewing lead for Senior Advisor assignment.',
            ],
            [
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
                'legacy_status' => 'Counselling In Progress',
                'description' => 'Senior Advisor actively counselling student for enrollment.',
            ],
            [
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
                'legacy_status' => 'Recovery Requested',
                'description' => 'Advisor requested recovery; Sales Head reviewing for PSA assignment.',
            ],
            [
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
                'legacy_status' => 'PSA Recovery',
                'description' => 'PSA handling objection resolution and student reassessment.',
            ],
            [
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
                'legacy_status' => 'Returned to Advisor',
                'description' => 'PSA successfully recovered lead; returned to Advisor for final admission.',
            ],
            [
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
                'legacy_status' => 'Admission Confirmed',
                'description' => 'Admission confirmed, documents collected, and fee paid.',
            ],
            [
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
                'legacy_status' => 'Closed Lost',
                'description' => 'Lead closed as non-convertible with specific lost reason.',
            ],
        ];

        $stageMap = [];
        foreach ($stages as $stageData) {
            $filteredData = array_filter($stageData, fn ($k) => Schema::hasColumn('lead_stages', $k), ARRAY_FILTER_USE_KEY);
            $stage = LeadStage::query()->updateOrCreate(['key' => $stageData['key']], $filteredData);
            $stageMap[$stageData['key']] = $stage;
        }

        // 2. Closed / Lost Reasons (Separate table)
        if (Schema::hasTable('lead_closed_reasons')) {
            $closedReasons = [
                ['key' => 'wrong_number', 'label' => 'Wrong Number', 'color' => '#6b7280', 'sort_order' => 1],
                ['key' => 'duplicate', 'label' => 'Duplicate Lead', 'color' => '#4b5563', 'sort_order' => 2],
                ['key' => 'fake_lead', 'label' => 'Fake Lead / Invalid', 'color' => '#991b1b', 'sort_order' => 3],
                ['key' => 'budget_issue', 'label' => 'Budget Issue', 'color' => '#f97316', 'sort_order' => 4],
                ['key' => 'joined_competitor', 'label' => 'Joined Competitor', 'color' => '#ef4444', 'sort_order' => 5],
                ['key' => 'not_interested', 'label' => 'Not Interested', 'color' => '#6b7280', 'sort_order' => 6],
                ['key' => 'no_response', 'label' => 'No Response (NATC)', 'color' => '#78716c', 'sort_order' => 7],
                ['key' => 'parent_rejected', 'label' => 'Parent Rejected', 'color' => '#b91c1c', 'sort_order' => 8],
            ];

            foreach ($closedReasons as $reason) {
                LeadClosedReason::query()->updateOrCreate(['key' => $reason['key']], $reason);
            }
        }

        // 3. Allowed Stage Transitions (State Machine Rules)
        if (Schema::hasTable('lead_stage_rules')) {
            $allowedLinearTransitions = [
                'new_lead' => ['assigned_telecaller'],
                'assigned_telecaller' => ['qualified', 'closed_lost'],
                'qualified' => ['sales_head_review'],
                'sales_head_review' => ['advisor_counselling', 'assigned_telecaller'],
                'advisor_counselling' => ['enrolled', 'recovery_required', 'closed_lost'],
                'recovery_required' => ['psa_recovery'],
                'psa_recovery' => ['returned_to_advisor', 'closed_lost'],
                'returned_to_advisor' => ['enrolled', 'closed_lost'],
            ];

            foreach ($allowedLinearTransitions as $fromKey => $toKeys) {
                $fromStage = $stageMap[$fromKey] ?? null;
                if (! $fromStage) continue;
                foreach ($toKeys as $toKey) {
                    $toStage = $stageMap[$toKey] ?? null;
                    if (! $toStage) continue;
                    LeadStageRule::query()->updateOrCreate([
                        'from_stage_id' => $fromStage->id,
                        'to_stage_id' => $toStage->id,
                    ], [
                        'is_active' => true,
                    ]);
                }
            }
        }

        // 4. Permission Matrix (lead_stage_permissions)
        if (Schema::hasTable('lead_stage_permissions')) {
            $roles = ['super_admin', 'admin', 'sales_head', 'department_head', 'telecaller', 'psa', 'advisor', 'marketer'];
            foreach ($stageMap as $stage) {
                foreach ($roles as $role) {
                    $isSuperOrHead = in_array($role, ['super_admin', 'admin', 'sales_head', 'department_head'], true);
                    $isOwnerRole = ($stage->owner_role === $role);

                    $canView = true;
                    if ($role === 'sales_head') {
                        // Include qualified (order 3) so sales-head-created / telecaller-handoff leads are listable.
                        $canView = $stage->order >= 3;
                    }

                    LeadStagePermission::query()->updateOrCreate([
                        'lead_stage_id' => $stage->id,
                        'role' => $role,
                    ], [
                        'can_view' => $canView,
                        'can_move' => $isSuperOrHead || $isOwnerRole,
                        'can_override' => $isSuperOrHead,
                        'can_close' => $isSuperOrHead || $isOwnerRole,
                        'can_reopen' => $isSuperOrHead,
                        'can_delete' => in_array($role, ['super_admin', 'admin'], true),
                    ]);
                }
            }
        }

        // 5. Automations & Required Fields
        if (Schema::hasTable('lead_stage_required_fields')) {
            if (isset($stageMap['qualified'])) {
                $required = [
                    ['field_name' => 'course_interested', 'field_label' => 'Course Interested'],
                ];
                foreach ($required as $req) {
                    LeadStageRequiredField::query()->updateOrCreate([
                        'lead_stage_id' => $stageMap['qualified']->id,
                        'field_name' => $req['field_name'],
                    ], ['field_label' => $req['field_label'], 'is_required' => true]);
                }
        }
    }
}
}
