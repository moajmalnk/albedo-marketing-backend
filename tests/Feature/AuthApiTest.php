<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    private function createRole(string $key, string $name, int $permissionLevel): Role
    {
        return Role::query()->create(['key' => $key, 'name' => $name, 'permission_level' => $permissionLevel]);
    }

    private function createUser(Role $role, array $overrides = []): User
    {
        $email = $overrides['email'] ?? str_replace('_', '.', $role->key).'@test.com';

        return User::query()->create(array_merge([
            'first_name' => ucfirst(str_replace('_', ' ', $role->key)),
            'email' => $email,
            'password_hash' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'active',
        ], $overrides));
    }

    public function test_user_can_login_and_get_token(): void
    {
        $role = $this->createRole('admin', 'Admin', 90);
        $this->createUser($role, ['email' => 'admin@test.com']);

        $response = $this->postJson('/api/v1/auth/login', ['email' => 'admin@test.com', 'password' => 'password']);
        $response->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_login_succeeds_without_xsrf_when_request_is_stateful_spa_origin(): void
    {
        config(['sanctum.stateful' => ['marketing.albedoedu.com']]);

        $role = $this->createRole('admin', 'Admin', 90);
        $this->createUser($role, ['email' => 'spa@test.com', 'password_hash' => Hash::make('secret')]);

        $response = $this->postJson(
            '/api/v1/auth/login',
            ['email' => 'spa@test.com', 'password' => 'secret'],
            ['Origin' => 'https://marketing.albedoedu.com']
        );

        $response->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $role = $this->createRole('admin', 'Admin', 90);
        $this->createUser($role, ['email' => 'inactive@test.com', 'status' => 'inactive']);

        $response = $this->postJson('/api/v1/auth/login', ['email' => 'inactive@test.com', 'password' => 'password']);

        $response->assertForbidden()->assertJson(['message' => 'Your account is inactive.']);
    }

    public function test_admin_can_impersonate_active_user_and_return_token_works(): void
    {
        $adminRole = $this->createRole('admin', 'Admin', 90);
        $telecallerRole = $this->createRole('telecaller', 'Telecaller', 40);
        $admin = $this->createUser($adminRole, ['email' => 'admin@test.com']);
        $target = $this->createUser($telecallerRole, ['email' => 'target@test.com']);
        $adminToken = $admin->createToken('api-token')->plainTextToken;

        $response = $this->postJson("/api/v1/users/{$target->id}/impersonate", [], [
            'Authorization' => 'Bearer '.$adminToken,
        ]);

        $response->assertOk()->assertJsonStructure([
            'token',
            'user' => ['id', 'email', 'role'],
            'impersonation' => ['actor_id', 'actor_name', 'expires_at'],
        ]);
        $response->assertJsonPath('user.id', $target->id);
        $response->assertJsonPath('impersonation.actor_id', $admin->id);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'user.impersonate',
            'entity_type' => 'user',
            'entity_id' => $target->id,
        ]);
        $this->assertSame(1, AuditLog::query()->where('action', 'user.impersonate')->count());
        $impersonationToken = $target->tokens()->where('name', 'impersonation-token')->first();
        $this->assertNotNull($impersonationToken);
        $this->assertTrue(Carbon::parse($impersonationToken->expires_at)->greaterThan(now()->addDays(6)));

        auth()->forgetGuards();

        $this
            ->withHeader('Authorization', 'Bearer '.$response->json('token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $target->id);
    }

    public function test_non_admin_cannot_impersonate_user(): void
    {
        $telecallerRole = $this->createRole('telecaller', 'Telecaller', 40);
        $actor = $this->createUser($telecallerRole, ['email' => 'actor@test.com']);
        $target = $this->createUser($telecallerRole, ['email' => 'target@test.com']);

        Sanctum::actingAs($actor);

        $this->postJson("/api/v1/users/{$target->id}/impersonate")
            ->assertForbidden()
            ->assertJson(['message' => 'You are not authorized to impersonate users.']);
    }

    public function test_admin_cannot_impersonate_inactive_user(): void
    {
        $adminRole = $this->createRole('admin', 'Admin', 90);
        $telecallerRole = $this->createRole('telecaller', 'Telecaller', 40);
        $admin = $this->createUser($adminRole, ['email' => 'admin@test.com']);
        $target = $this->createUser($telecallerRole, ['email' => 'inactive-target@test.com', 'status' => 'inactive']);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/users/{$target->id}/impersonate")
            ->assertUnprocessable()
            ->assertJson(['message' => 'Only active users can be impersonated.']);
    }

    public function test_admin_cannot_impersonate_self(): void
    {
        $adminRole = $this->createRole('admin', 'Admin', 90);
        $admin = $this->createUser($adminRole, ['email' => 'admin@test.com']);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/users/{$admin->id}/impersonate")
            ->assertUnprocessable()
            ->assertJson(['message' => 'You cannot impersonate your own account.']);
    }
}
