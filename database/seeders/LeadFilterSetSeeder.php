<?php

namespace Database\Seeders;

use App\Models\LeadFilterSet;
use Illuminate\Database\Seeder;

class LeadFilterSetSeeder extends Seeder
{
    public function run(): void
    {
        $presets = [
            [
                'name' => 'New Lead',
                'description' => 'Leads currently in New Lead stage',
                'criteria' => [
                    'status_filters' => ['New Lead'],
                ],
                'sort_order' => 1,
            ],
            [
                'name' => 'Unassigned Performance',
                'description' => 'Performance Marketing leads that need an owner',
                'criteria' => [
                    'source_group' => 'performance',
                    'owner_id' => null,
                ],
                'sort_order' => 2,
            ],
        ];

        foreach ($presets as $preset) {
            LeadFilterSet::query()->updateOrCreate(
                ['name' => $preset['name']],
                [
                    'description' => $preset['description'],
                    'criteria' => $preset['criteria'],
                    'is_active' => true,
                    'sort_order' => $preset['sort_order'],
                ]
            );
        }
    }
}
