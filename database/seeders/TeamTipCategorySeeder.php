<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TeamTipCategory;
use Illuminate\Support\Str;

class TeamTipCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Support',
            'Sales',
            'Marketing',
            'Operations',
            'Attendance',
            'HR',
            'Admissions',
            'General',
            'Products',
            'Training',
            'Announcements',
            'System Updates',
        ];

        foreach ($categories as $cat) {
            TeamTipCategory::updateOrCreate(
                ['slug' => Str::slug($cat)],
                ['name' => $cat]
            );
        }
    }
}
