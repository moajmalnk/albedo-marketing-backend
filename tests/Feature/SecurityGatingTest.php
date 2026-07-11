<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\MarketingChallenge;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityGatingTest extends TestCase
{
    use RefreshDatabase;

    private $superAdminRole;
    private $adminRole;
    private $deptHeadRole;
    private $telecallerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdminRole = Role::query()->create(['key' => 'super_admin', 'name' => 'Super Admin', 'permission_level' => 100]);
        $this->adminRole = Role::query()->create(['key' => 'admin', 'name' => 'Admin', 'permission_level' => 90]);
        $this->deptHeadRole = Role::query()->create(['key' => 'dept_head', 'name' => 'Dept Head', 'permission_level' => 80]);
        $this->telecallerRole = Role::query()->create(['key' => 'telecaller', 'name' => 'Telecaller', 'permission_level' => 10]);
    }

    private function createUser(Role $role, array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => uniqid() . '@test.com',
            'password_hash' => bcrypt('password'),
            'role_id' => $role->id,
            'status' => 'active',
            'department' => 'SALES',
        ], $overrides));
    }

    public function test_settings_endpoint_gated_by_role(): void
    {
        $telecaller = $this->createUser($this->telecallerRole);
        $admin = $this->createUser($this->adminRole);

        // Telecaller should be forbidden
        Sanctum::actingAs($telecaller);
        $this->getJson('/api/v1/settings')->assertStatus(403);
        $this->postJson('/api/v1/settings', ['settings' => []])->assertStatus(403);

        // Admin should be allowed
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/settings')->assertOk();
    }

    public function test_audit_logs_and_finance_only_accessible_by_super_admin(): void
    {
        $admin = $this->createUser($this->adminRole);
        $superAdmin = $this->createUser($this->superAdminRole);

        // Admin should be forbidden for audit-logs and finance summary
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/audit-logs')->assertStatus(403);
        $this->getJson('/api/v1/finance/summary')->assertStatus(403);

        // Super Admin should be allowed
        Sanctum::actingAs($superAdmin);
        $this->getJson('/api/v1/audit-logs')->assertOk();
        $this->getJson('/api/v1/finance/summary')->assertOk();
    }

    public function test_challenges_index_is_department_scoped(): void
    {
        $salesUser = $this->createUser($this->telecallerRole, ['department' => 'SALES']);
        $pmUser = $this->createUser($this->telecallerRole, ['department' => 'PM']);

        MarketingChallenge::query()->create([
            'category' => 'Lead Quality',
            'description' => 'Sales issue',
            'department' => 'Sales',
            'reported_by' => 'Sales Agent',
            'status' => 'Open',
            'date_reported' => now()->toDateString(),
        ]);

        MarketingChallenge::query()->create([
            'category' => 'Ad Campaign',
            'description' => 'PM issue',
            'department' => 'Performance Marketing',
            'reported_by' => 'PM Agent',
            'status' => 'Open',
            'date_reported' => now()->toDateString(),
        ]);

        // Sales user should only see challenges under 'Sales' department
        Sanctum::actingAs($salesUser);
        $response = $this->getJson('/api/v1/marketing-challenges');
        $response->assertOk()->assertJsonCount(1);
        $this->assertEquals('Sales', $response->json()[0]['department']);

        // PM user should only see challenges under 'Performance Marketing' department
        Sanctum::actingAs($pmUser);
        $response = $this->getJson('/api/v1/marketing-challenges');
        $response->assertOk()->assertJsonCount(1);
        $this->assertEquals('Performance Marketing', $response->json()[0]['department']);
    }

    public function test_challenges_actions_respect_policies(): void
    {
        $salesUser = $this->createUser($this->telecallerRole, ['department' => 'SALES']);
        $salesDeptHead = $this->createUser($this->deptHeadRole, ['department' => 'SALES']);
        $pmDeptHead = $this->createUser($this->deptHeadRole, ['department' => 'PM']);
        
        $challenge = MarketingChallenge::query()->create([
            'category' => 'Lead Quality',
            'description' => 'Sales issue',
            'department' => 'Sales',
            'reported_by' => 'Sales Agent',
            'status' => 'Open',
            'created_by' => $salesUser->id, // Created by sales user
            'date_reported' => now()->toDateString(),
        ]);

        // Creator can update
        Sanctum::actingAs($salesUser);
        $this->patchJson("/api/v1/marketing-challenges/{$challenge->id}", ['status' => 'In Progress'])
            ->assertOk();

        // Different department head cannot update
        Sanctum::actingAs($pmDeptHead);
        $this->patchJson("/api/v1/marketing-challenges/{$challenge->id}", ['status' => 'Resolved'])
            ->assertStatus(403);

        // Same department head can update
        Sanctum::actingAs($salesDeptHead);
        $this->patchJson("/api/v1/marketing-challenges/{$challenge->id}", ['status' => 'Resolved'])
            ->assertOk();
    }
}
