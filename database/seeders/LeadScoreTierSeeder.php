<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

use App\Models\LeadScoreTier;

class LeadScoreTierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        LeadScoreTier::create(['name' => 'Hot Lead', 'min_score' => 70, 'max_score' => null]);
        LeadScoreTier::create(['name' => 'Warm Lead', 'min_score' => 40, 'max_score' => 69]);
        LeadScoreTier::create(['name' => 'Cold Lead', 'min_score' => -9999, 'max_score' => 39]);
    }
}
