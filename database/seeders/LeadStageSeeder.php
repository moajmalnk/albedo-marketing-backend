<?php

namespace Database\Seeders;

use App\Models\LeadStage;
use Illuminate\Database\Seeder;

/**
 * Keep keys/labels aligned with the marketing SPA `src/lib/pipelineStages.ts`.
 */
class LeadStageSeeder extends Seeder
{
    public function run(): void
    {
        $stages = [
            // Active funnel (order matches stepper left → right)
            ['key' => 'new_lead', 'label' => 'New Lead', 'group' => 'active', 'order' => 1, 'color' => '#3b82f6', 'is_terminal' => false],
            ['key' => 'prospect', 'label' => 'Prospect', 'group' => 'active', 'order' => 2, 'color' => '#06b6d4', 'is_terminal' => false],
            ['key' => 'demo_required', 'label' => 'Demo Required', 'group' => 'active', 'order' => 3, 'color' => '#6366f1', 'is_terminal' => false],
            ['key' => 'itb', 'label' => 'Interested To Buy', 'group' => 'active', 'order' => 4, 'color' => '#f97316', 'is_terminal' => false],
            ['key' => 'follow_up', 'label' => 'Follow Up', 'group' => 'active', 'order' => 5, 'color' => '#eab308', 'is_terminal' => false],
            ['key' => 'dnp', 'label' => 'Do Not Picked', 'group' => 'active', 'order' => 6, 'color' => '#64748b', 'is_terminal' => false],
            ['key' => 'assessment_booked', 'label' => 'Assessment Booked', 'group' => 'active', 'order' => 7, 'color' => '#a855f7', 'is_terminal' => false],
            ['key' => 'assessment_done', 'label' => 'Assessment Done', 'group' => 'active', 'order' => 8, 'color' => '#7c3aed', 'is_terminal' => false],
            ['key' => 'enrolled', 'label' => 'Enrolled', 'group' => 'active', 'order' => 9, 'color' => '#22c55e', 'is_terminal' => false],
            // Inactive / closed (terminal except may_buy_later)
            ['key' => 'nifc', 'label' => 'NIFC', 'group' => 'inactive', 'order' => 10, 'color' => '#6b7280', 'is_terminal' => true],
            ['key' => 'first_call_nifc', 'label' => 'First Call NIFC', 'group' => 'inactive', 'order' => 11, 'color' => '#6b7280', 'is_terminal' => true],
            ['key' => 'invalid_junk', 'label' => 'Invalid / Junk', 'group' => 'inactive', 'order' => 12, 'color' => '#4b5563', 'is_terminal' => true],
            ['key' => 'disqualified', 'label' => 'Disqualified', 'group' => 'inactive', 'order' => 13, 'color' => '#991b1b', 'is_terminal' => true],
            ['key' => 'may_buy_later', 'label' => 'May Buy Later', 'group' => 'inactive', 'order' => 14, 'color' => '#78716c', 'is_terminal' => false],
            ['key' => 'natc', 'label' => 'NATC', 'group' => 'inactive', 'order' => 15, 'color' => '#57534e', 'is_terminal' => true],
            ['key' => 'duplicate_lead', 'label' => 'Duplicate Lead', 'group' => 'inactive', 'order' => 16, 'color' => '#44403c', 'is_terminal' => true],
            ['key' => 'job_enquiry', 'label' => 'Job Enquiry', 'group' => 'inactive', 'order' => 17, 'color' => '#292524', 'is_terminal' => true],
        ];

        foreach ($stages as $stage) {
            LeadStage::query()->updateOrCreate(['key' => $stage['key']], $stage);
        }
    }
}
