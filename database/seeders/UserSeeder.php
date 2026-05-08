<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminRoleId = Role::query()->where('key', 'super_admin')->value('id');
        if (! $adminRoleId) {
            return;
        }

        User::query()->updateOrCreate(
            ['email' => 'ramees@albedo.local'],
            [
                'first_name' => 'Ramees',
                'last_name' => 'Admin',
                'password_hash' => Hash::make('Admin@12345'),
                'role_id' => $adminRoleId,
                'department' => 'OPS',
                'status' => 'active',
            ]
        );
    }
}
