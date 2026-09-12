<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Personal "My Filters" are user-owned via created_by.
 * Org-wide seeded presets are intentionally not created.
 */
class LeadFilterSetSeeder extends Seeder
{
    public function run(): void
    {
        // no-op — filter sets are created per-user from the UI
    }
}
