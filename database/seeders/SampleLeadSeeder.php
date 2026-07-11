<?php

namespace Database\Seeders;

use App\Models\Lead;
use App\Models\LeadStage;
use App\Models\User;
use Illuminate\Database\Seeder;

class SampleLeadSeeder extends Seeder
{
    public function run(): void
    {
        $haifa = User::query()->where('email', 'haifa@albedoedu.com')->first();
        $diya = User::query()->where('email', 'diya@albedoedu.com')->first();
        $shahana = User::query()->where('email', 'shahana@albedoedu.com')->first();
        $rinsha = User::query()->where('email', 'rinsha@albedoedu.com')->first();

        $followUpStage = LeadStage::query()->where('key', 'assigned_telecaller')->value('id');
        $newLeadStage = LeadStage::query()->where('key', 'new_lead')->value('id');
        $assessmentDoneStage = LeadStage::query()->where('key', 'psa_recovery')->value('id');
        $prospectStage = LeadStage::query()->where('key', 'qualified')->value('id');

        $leads = [
            [
                'student_name' => 'John Doe',
                'phone' => '+919999999901',
                'whatsapp' => '+919999999901',
                'email' => 'john.doe@example.com',
                'course' => 'Foundation',
                'status' => 'Follow-up',
                'owner_id' => $haifa?->id,
                'stage_id' => $followUpStage,
                'assigned_dept' => 'SALES',
            ],
            [
                'student_name' => 'Jane Smith',
                'phone' => '+919999999902',
                'whatsapp' => '+919999999902',
                'email' => 'jane.smith@example.com',
                'course' => 'Academics',
                'status' => 'New',
                'owner_id' => $diya?->id,
                'stage_id' => $newLeadStage,
                'assigned_dept' => 'SALES',
            ],
            [
                'student_name' => 'Bob Johnson',
                'phone' => '+919999999903',
                'whatsapp' => '+919999999903',
                'email' => 'bob.johnson@example.com',
                'course' => 'Foundation',
                'status' => 'Qualified',
                'owner_id' => $shahana?->id,
                'stage_id' => $assessmentDoneStage,
                'assigned_dept' => 'SALES',
            ],
            [
                'student_name' => 'Alice Brown',
                'phone' => '+919999999904',
                'whatsapp' => '+919999999904',
                'email' => 'alice.brown@example.com',
                'course' => 'Crash',
                'status' => 'Follow-up',
                'owner_id' => $rinsha?->id,
                'stage_id' => $prospectStage,
                'assigned_dept' => 'SALES',
            ],
        ];

        foreach ($leads as $lead) {
            Lead::query()->updateOrCreate(['phone' => $lead['phone']], $lead);
        }
    }
}
