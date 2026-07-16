<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\LeadStage;
use Illuminate\Support\Facades\DB;

class SeedBifurcatedPipelinesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function () {
            // 1. Update Existing Marketing Stages
            $marketingUpdates = [
                1 => ['key' => 'new_lead', 'label' => 'New Lead', 'type' => 'open'],
                2 => ['key' => 'attempted_contact_1', 'label' => 'Attempted Contact 1', 'type' => 'open'],
                3 => ['key' => 'attempted_contact_2', 'label' => 'Attempted Contact 2', 'type' => 'open'],
                4 => ['key' => 'attempted_contact_3', 'label' => 'Attempted Contact 3', 'type' => 'open'],
                5 => ['key' => 'connected', 'label' => 'Connected / Talking', 'type' => 'open'],
                6 => ['key' => 'qualified_for_sales', 'label' => 'Qualified for Sales', 'type' => 'open'],
                7 => ['key' => 'nurture', 'label' => 'Nurture / Follow-up', 'type' => 'open'],
                8 => ['key' => 'not_interested', 'label' => 'Not Interested', 'type' => 'lost'],
                9 => ['key' => 'junk', 'label' => 'Junk / Invalid', 'type' => 'lost'],
                10 => ['key' => 'duplicate', 'label' => 'Duplicate', 'type' => 'lost'],
                11 => ['key' => 'handed_to_sales', 'label' => 'Handed Off to Sales', 'type' => 'won'],
            ];

            foreach ($marketingUpdates as $id => $data) {
                LeadStage::where('id', $id)->update([
                    'key' => $data['key'],
                    'label' => $data['label'],
                    'type' => $data['type'],
                    'team' => 'marketing',
                    'is_terminal' => in_array($data['type'], ['won', 'lost']),
                    'order' => $id,
                    'group' => 'active',
                    'is_active' => true,
                ]);
            }

            // 2. Insert New Sales Stages
            // Determine max order
            $startOrder = 20;

            $salesStages = [
                ['key' => 'sales_new_lead', 'label' => 'New Lead', 'type' => 'open', 'color' => '#3b82f6'],
                ['key' => 'prospect', 'label' => 'Prospect', 'type' => 'open', 'color' => '#8b5cf6'],
                ['key' => 'first_call_nifc', 'label' => 'First call NIFC', 'type' => 'open', 'color' => '#f59e0b'],
                ['key' => 'demo_required', 'label' => 'Demo Required', 'type' => 'open', 'color' => '#ec4899'],
                ['key' => 'assessment_booked', 'label' => 'ASSESSMENT BOOKED', 'type' => 'open', 'color' => '#06b6d4'],
                ['key' => 'assessment_done', 'label' => 'ASSESSMENT DONE', 'type' => 'open', 'color' => '#10b981'],
                ['key' => 'interested_to_buy', 'label' => 'Interested To Buy (ITB)', 'type' => 'open', 'color' => '#84cc16'],
                ['key' => 'follow_up', 'label' => 'Follow up', 'type' => 'open', 'color' => '#f97316'],
                ['key' => 'may_buy_later', 'label' => 'May Buy Later', 'type' => 'open', 'color' => '#eab308'],
                ['key' => 'sales_enrolled', 'label' => 'Enrolled', 'type' => 'won', 'color' => '#22c55e'],
                ['key' => 'nifc', 'label' => 'Not Interested In Full Course (NIFC)', 'type' => 'lost', 'color' => '#ef4444'],
                ['key' => 'natc', 'label' => 'Not Able To Connect (NATC)', 'type' => 'lost', 'color' => '#dc2626'],
                ['key' => 'dnp', 'label' => 'Do Not Picked (DNP)', 'type' => 'lost', 'color' => '#b91c1c'],
                ['key' => 'disqualified', 'label' => 'Disqualified', 'type' => 'lost', 'color' => '#991b1b'],
                ['key' => 'invalid_junk', 'label' => 'Invalid/ Junk', 'type' => 'lost', 'color' => '#7f1d1d'],
                ['key' => 'duplicate_lead', 'label' => 'DUPLICATE LEAD', 'type' => 'lost', 'color' => '#450a0a'],
                ['key' => 'job_enquiry', 'label' => 'JOB ENQUIRY', 'type' => 'lost', 'color' => '#a3a3a3'],
            ];

            foreach ($salesStages as $index => $stage) {
                // If it already exists by key, update it, else create
                LeadStage::updateOrCreate(
                    ['key' => $stage['key']],
                    [
                        'label' => $stage['label'],
                        'type' => $stage['type'],
                        'team' => 'sales',
                        'is_terminal' => in_array($stage['type'], ['won', 'lost']),
                        'color' => $stage['color'],
                        'order' => $startOrder + $index,
                        'group' => 'active',
                        'is_active' => true,
                        'sla_hours' => 24,
                    ]
                );
            }
        });
    }
}
