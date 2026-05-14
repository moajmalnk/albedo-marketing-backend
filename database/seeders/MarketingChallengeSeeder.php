<?php

namespace Database\Seeders;

use App\Models\MarketingChallenge;
use App\Models\User;
use Illuminate\Database\Seeder;

class MarketingChallengeSeeder extends Seeder
{
    public function run(): void
    {
        $uid = User::query()->orderBy('id')->value('id');

        $rows = [
            [
                'category' => 'Influence Marketing Lead quality',
                'description' => 'Leads from influencer campaigns have low intent — many are just curious, not serious buyers',
                'department' => 'Influence Marketing',
                'reported_by' => 'Ajmal',
                'affected_leads' => ['LEAD-0023', 'LEAD-0045', 'LEAD-0067'],
                'status' => 'Open',
                'date_reported' => '2026-04-10',
                'date_resolved' => null,
                'notes' => null,
            ],
            [
                'category' => 'Sales closing hurdle',
                'description' => 'Parents comparing prices with local tuition centers — need better USP positioning',
                'department' => 'Performance Marketing',
                'reported_by' => 'Naseef',
                'affected_leads' => ['LEAD-0012', 'LEAD-0034'],
                'status' => 'In Progress',
                'date_reported' => '2026-04-08',
                'date_resolved' => null,
                'notes' => 'Creating comparison sheet for telecallers',
            ],
            [
                'category' => 'Syllabus choosing confusion',
                'description' => 'Students unsure between CBSE and State Board — telecallers spending too much time explaining',
                'department' => 'Performance Marketing',
                'reported_by' => 'Shahana',
                'affected_leads' => ['LEAD-0056'],
                'status' => 'Resolved',
                'date_reported' => '2026-04-05',
                'date_resolved' => '2026-04-12',
                'notes' => 'Created syllabus comparison document shared with all telecallers',
            ],
            [
                'category' => 'Lead tracking gap',
                'description' => 'Some WhatsApp leads not being captured in the system — manual entry needed',
                'department' => 'Performance Marketing',
                'reported_by' => 'Haifa',
                'affected_leads' => [],
                'status' => 'Open',
                'date_reported' => '2026-04-14',
                'date_resolved' => null,
                'notes' => null,
            ],
        ];

        foreach ($rows as $r) {
            MarketingChallenge::query()->firstOrCreate(
                [
                    'category' => $r['category'],
                    'date_reported' => $r['date_reported'],
                    'department' => $r['department'],
                ],
                array_merge($r, ['created_by' => $uid])
            );
        }
    }
}
