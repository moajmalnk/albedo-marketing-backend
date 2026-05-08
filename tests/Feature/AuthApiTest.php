<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_and_get_token(): void
    {
        $role = Role::query()->create(['key' => 'admin', 'name' => 'Admin', 'permission_level' => 90]);
        User::query()->create([
            'first_name' => 'Admin',
            'email' => 'admin@test.com',
            'password_hash' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/auth/login', ['email' => 'admin@test.com', 'password' => 'password']);
        $response->assertOk()->assertJsonStructure(['token', 'user']);
    }
}
