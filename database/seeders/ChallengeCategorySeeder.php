<?php

namespace Database\Seeders;

use App\Models\ChallengeCategory;
use Illuminate\Database\Seeder;

class ChallengeCategorySeeder extends Seeder
{
    public function run(): void
    {
        $pm = [
            'Ad creative fatigue',
            'Lead quality from paid ads',
            'CPL increase',
            'Landing page conversion',
            'Pixel / tracking issue',
            'Sales closing hurdle',
            'Other',
        ];
        $im = [
            'Influencer lead quality',
            'Campaign coordination',
            'Influencer payment delay',
            'Content approval delay',
            'Reach / engagement drop',
            'Sales closing hurdle',
            'Other',
        ];

        foreach ($pm as $name) {
            ChallengeCategory::query()->firstOrCreate(
                ['name' => $name, 'department' => 'Performance Marketing'],
                ['status' => 'Active']
            );
        }
        foreach ($im as $name) {
            ChallengeCategory::query()->firstOrCreate(
                ['name' => $name, 'department' => 'Influence Marketing'],
                ['status' => 'Active']
            );
        }
    }
}
