<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            ['key' => 'super_admin', 'name' => 'Super Admin', 'permission_level' => 100],
            ['key' => 'admin', 'name' => 'Admin', 'permission_level' => 90],
            ['key' => 'dept_head', 'name' => 'Dept Head', 'permission_level' => 80],
            ['key' => 'marketer', 'name' => 'Marketer', 'permission_level' => 40],
            ['key' => 'psa', 'name' => 'PSA', 'permission_level' => 30],
            ['key' => 'advisor', 'name' => 'Advisor', 'permission_level' => 20],
            ['key' => 'telecaller', 'name' => 'Telecaller', 'permission_level' => 10],
        ];

        foreach ($roles as $role) {
            Role::query()->updateOrCreate(['key' => $role['key']], $role);
        }
    }
}
