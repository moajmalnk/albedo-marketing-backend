<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            LeadStageSeeder::class,
            LeadFormOptionSeeder::class,
            UserSeeder::class,
            ChallengeCategorySeeder::class,
            LeadFilterSetSeeder::class,
        ]);

        if (app()->environment('local', 'testing', 'development')) {
            $this->call([
                MarketingChallengeSeeder::class,
                TeamTipSeeder::class,
            ]);
        }
    }
}
