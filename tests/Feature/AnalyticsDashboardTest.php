<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadStage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function seedStages(): void
    {
        foreach (['new_lead', 'prospect', 'itb', 'enrolled'] as $key) {
            LeadStage::query()->firstOrCreate(
                ['key' => $key],
                ['label' => $key, 'group' => 'active', 'order' => 1, 'color' => '#000', 'is_terminal' => false]
            );
        }
    }

    private function makeUser(string $roleKey): User
    {
        $role = Role::query()->firstOrCreate(
            ['key' => $roleKey],
            ['name' => $roleKey, 'permission_level' => 90]
        );

        return User::query()->create([
            'first_name' => 'Test',
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'active',
            'department' => 'PM',
        ]);
    }

    public function test_marketing_analytics_forbidden_for_telecaller(): void
    {
        $this->seedStages();
        $user = $this->makeUser('telecaller');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/analytics/marketing')->assertForbidden();
    }

    public function test_marketing_analytics_ok_for_admin(): void
    {
        $this->seedStages();
        $admin = $this->makeUser('admin');
        $stage = LeadStage::query()->where('key', 'new_lead')->firstOrFail();
        Lead::query()->create([
            'student_name' => 'A',
            'phone' => '911111111111',
            'stage_id' => $stage->id,
            'source_code' => 'whatsapp',
        ]);

        Sanctum::actingAs($admin);
        $res = $this->getJson('/api/v1/analytics/marketing');
        $res->assertOk();
        $res->assertJsonStructure([
            'channels' => ['whatsapp', 'form', 'call', 'message'],
            'totals' => ['total_leads', 'qualified_leads', 'conversion_percent'],
            'goal' => ['percentage', 'month_to_date_leads', 'monthly_target'],
            'weekly_lead_intake',
            'staff_preview',
        ]);
        $this->assertGreaterThanOrEqual(1, $res->json('channels.whatsapp'));
    }

    public function test_role_summary_for_telecaller(): void
    {
        $this->seedStages();
        $tc = $this->makeUser('telecaller');
        $stage = LeadStage::query()->where('key', 'new_lead')->firstOrFail();
        Lead::query()->create([
            'student_name' => 'Owned',
            'phone' => '922222222222',
            'stage_id' => $stage->id,
            'owner_id' => $tc->id,
        ]);

        Sanctum::actingAs($tc);
        $this->getJson('/api/v1/analytics/role-summary')
            ->assertOk()
            ->assertJsonPath('role', 'telecaller')
            ->assertJsonPath('owned_total', 1);
    }

    public function test_leads_index_filters_by_generated_by_user_id(): void
    {
        $this->seedStages();
        $admin = $this->makeUser('admin');
        $marketer = $this->makeUser('marketer');
        $stage = LeadStage::query()->where('key', 'new_lead')->firstOrFail();

        Lead::query()->create([
            'student_name' => 'M1',
            'phone' => '933333333333',
            'stage_id' => $stage->id,
            'generated_by_user_id' => $marketer->id,
        ]);
        Lead::query()->create([
            'student_name' => 'M2',
            'phone' => '944444444444',
            'stage_id' => $stage->id,
            'generated_by_user_id' => $admin->id,
        ]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/leads?generated_by_user_id='.$marketer->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
