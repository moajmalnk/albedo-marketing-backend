<?php

namespace Database\Seeders;

use App\Models\TeamTip;
use App\Models\User;
use Illuminate\Database\Seeder;

class TeamTipSeeder extends Seeder
{
    public function run(): void
    {
        $uid = User::query()->orderBy('id')->value('id');

        $rows = [
            [
                'title' => 'Must Close 3 Leads From 10 Leads',
                'description' => 'Every telecaller must close at least 3 leads from every 10 leads assigned. This is non-negotiable and tracked daily.',
                'sent_to' => ['All Telecallers'],
                'sent_by' => 'Dilshada',
                'sent_by_role' => 'Admin',
                'date_sent' => '2026-04-10',
                'status' => 'Active',
                'priority' => 'High',
                'read_count' => 8,
            ],
            [
                'title' => '30% Conversion Rate — Your Target',
                'description' => 'Out of every 100 leads, target 30% immediate conversion. Move all unclosed leads to the follow-up pipeline. Your performance is reviewed monthly against this benchmark.',
                'sent_to' => ['All Telecallers'],
                'sent_by' => 'Dilshada',
                'sent_by_role' => 'Admin',
                'date_sent' => '2026-04-08',
                'status' => 'Active',
                'priority' => 'High',
                'read_count' => 6,
            ],
            [
                'title' => 'Monthly Follow-up Until Deal is Finalized',
                'description' => 'For any leads not closed yet, schedule a monthly follow-up until the deal is finalized. Do not abandon a lead — re-engage every 30 days.',
                'sent_to' => ['Performance Marketing', 'PM', 'Influence Marketing', 'IM'],
                'sent_by' => 'Naseef',
                'sent_by_role' => 'Department Head',
                'date_sent' => '2026-04-05',
                'status' => 'Active',
                'priority' => 'Normal',
                'read_count' => 7,
            ],
            [
                'title' => 'Tone & Greeting',
                'description' => 'Always start with a warm greeting and confirm the parent name before discussing the student. This builds trust within the first 10 seconds.',
                'sent_to' => ['All Users'],
                'sent_by' => 'Ramees',
                'sent_by_role' => 'CEO',
                'date_sent' => '2026-04-12',
                'status' => 'Active',
                'priority' => 'Normal',
                'read_count' => 4,
            ],
            [
                'title' => 'Lead Quality Target',
                'description' => 'Focus on Kerala-based leads for the next 24 hours to hit the A+ Campus CBSE target. Prioritize high-intent districts (Ernakulam, Kozhikode, Thiruvananthapuram) when running Meta and Google campaigns.',
                'sent_to' => ['Marketers'],
                'sent_by' => 'Dilshada',
                'sent_by_role' => 'Marketing Head',
                'date_sent' => '2026-04-15',
                'status' => 'Active',
                'priority' => 'High',
                'read_count' => 0,
            ],
            [
                'title' => 'Duplicate Prevention',
                'description' => 'Please ensure you use the Standard CSV Template available in the Import Center to avoid phone number duplication. The system will skip duplicates automatically, but using the template keeps your import success rate above 95%.',
                'sent_to' => ['Marketers'],
                'sent_by' => 'Dilshada',
                'sent_by_role' => 'Marketing Head',
                'date_sent' => '2026-04-13',
                'status' => 'Active',
                'priority' => 'Normal',
                'read_count' => 0,
            ],
            [
                'title' => 'Monthly Follow-up Push',
                'description' => 'Remind telecallers via lead notes to move unclosed deals to the monthly bucket. Add a short note like "Re-engage in 30 days" when handing over leads that need long-form nurturing.',
                'sent_to' => ['Marketers'],
                'sent_by' => 'Naseef',
                'sent_by_role' => 'Department Head',
                'date_sent' => '2026-04-11',
                'status' => 'Active',
                'priority' => 'Normal',
                'read_count' => 0,
            ],
        ];

        foreach ($rows as $r) {
            TeamTip::query()->firstOrCreate(
                [
                    'title' => $r['title'],
                    'date_sent' => $r['date_sent'],
                ],
                array_merge($r, ['created_by' => $uid])
            );
        }
    }
}
