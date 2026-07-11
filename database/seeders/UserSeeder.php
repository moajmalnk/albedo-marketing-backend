<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Seed all demo users that match the frontend mockUsers roster.
     * Password for every account: Admin@12345
     */
    public function run(): void
    {
        $password = Hash::make('Admin@12345');

        // Map role keys to their IDs
        $roles = Role::query()->pluck('id', 'key')->toArray();

        if (empty($roles)) {
            $this->command->error('Roles not seeded yet. Run RoleSeeder first.');
            return;
        }

        $superAdmin = ['first_name' => 'Ramees',       'last_name' => 'Admin',    'email' => 'ramees@albedoedu.com',       'phone' => '+919876543210', 'role_key' => 'super_admin', 'department' => 'OPS'];

        $users = [
            $superAdmin
        ];

        foreach ($users as $userData) {
            $roleId = $roles[$userData['role_key']] ?? null;
            if (! $roleId) {
                $this->command->warn("Role '{$userData['role_key']}' not found, skipping {$userData['email']}");
                continue;
            }

            User::query()->updateOrCreate(
                ['email' => $userData['email']],
                [
                    'first_name'    => $userData['first_name'],
                    'last_name'     => $userData['last_name'],
                    'password_hash' => $password,
                    'phone'         => $userData['phone'],
                    'role_id'       => $roleId,
                    'department'    => $userData['department'],
                    'status'        => 'active',
                ]
            );
        }

        $this->command->info('Seeded ' . count($users) . ' users.');
    }
}
