<?php

namespace Database\Seeders;

use App\Models\LeadStage;
use Illuminate\Database\Seeder;

class LeadStageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $stages = [
            ['key' => 'new_lead', 'label' => 'New Lead', 'group' => 'active', 'order' => 1],
            ['key' => 'prospect', 'label' => 'Prospect', 'group' => 'active', 'order' => 2],
            ['key' => 'assessment_booked', 'label' => 'Assessment Booked', 'group' => 'active', 'order' => 3],
            ['key' => 'itb', 'label' => 'ITB', 'group' => 'active', 'order' => 4],
            ['key' => 'enrolled', 'label' => 'Enrolled', 'group' => 'active', 'order' => 5],
            ['key' => 'disqualified', 'label' => 'Disqualified', 'group' => 'inactive', 'order' => 6, 'is_terminal' => true],
        ];

        foreach ($stages as $stage) {
            LeadStage::query()->updateOrCreate(['key' => $stage['key']], $stage);
        }
    }
}
