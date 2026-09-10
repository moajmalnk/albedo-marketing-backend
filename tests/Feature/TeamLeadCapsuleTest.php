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

class TeamLeadCapsuleTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $key, int $level = 50): Role
    {
        return Role::query()->updateOrCreate(
            ['key' => $key],
            ['name' => ucfirst(str_replace('_', ' ', $key)), 'permission_level' => $level]
        );
    }

    private function user(string $roleKey, array $overrides = []): User
    {
        $levels = [
            'super_admin' => 100,
            'admin' => 90,
            'sales_head' => 70,
            'team_lead' => 55,
            'psa' => 30,
            'advisor' => 20,
        ];
        $role = $this->role($roleKey, $levels[$roleKey] ?? 50);

        return User::query()->create(array_merge([
            'first_name' => ucfirst($roleKey),
            'last_name' => 'User',
            'email' => uniqid($roleKey.'_', true).'@test.com',
            'password_hash' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'active',
        ], $overrides));
    }

    private function salesStage(): LeadStage
    {
        $stage = LeadStage::query()->where('team', 'sales')->orderBy('order')->first()
            ?? LeadStage::query()->where('key', 'qualified')->first()
            ?? LeadStage::query()->where('key', 'advisor_counselling')->first();

        $this->assertNotNull($stage, 'Expected a sales-team lead stage to exist from migrations/seeders.');

        return $stage;
    }

    public function test_sales_head_can_create_team_lead_reporting_to_self(): void
    {
        $salesHead = $this->user('sales_head');
        $this->role('team_lead', 55);

        Sanctum::actingAs($salesHead);

        $response = $this->postJson('/api/v1/users', [
            'first_name' => 'Capsule',
            'last_name' => 'Lead',
            'email' => 'capsule.lead@test.com',
            'role_key' => 'team_lead',
            'password' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('role.key', 'team_lead')
            ->assertJsonPath('reporting_manager_id', $salesHead->id);
    }

    public function test_team_lead_creates_psa_forced_into_capsule(): void
    {
        $teamLead = $this->user('team_lead');
        $this->role('psa', 30);

        Sanctum::actingAs($teamLead);

        $response = $this->postJson('/api/v1/users', [
            'first_name' => 'Capsule',
            'last_name' => 'Psa',
            'email' => 'capsule.psa@test.com',
            'role_key' => 'psa',
            'password' => 'password123',
            'reporting_manager_id' => null,
        ]);

        $response->assertCreated()
            ->assertJsonPath('role.key', 'psa')
            ->assertJsonPath('reporting_manager_id', $teamLead->id);
    }

    public function test_team_lead_cannot_manage_psa_outside_capsule(): void
    {
        $teamLeadA = $this->user('team_lead');
        $teamLeadB = $this->user('team_lead');
        $foreignPsa = $this->user('psa', ['reporting_manager_id' => $teamLeadB->id]);

        Sanctum::actingAs($teamLeadA);

        $this->getJson('/api/v1/users/'.$foreignPsa->id)->assertForbidden();
        $this->patchJson('/api/v1/users/'.$foreignPsa->id, ['first_name' => 'Hacked'])->assertForbidden();
    }

    public function test_team_lead_can_assign_only_capsule_psa(): void
    {
        $teamLead = $this->user('team_lead');
        $ownPsa = $this->user('psa', ['reporting_manager_id' => $teamLead->id]);
        $otherLead = $this->user('team_lead');
        $foreignPsa = $this->user('psa', ['reporting_manager_id' => $otherLead->id]);

        $stage = $this->salesStage();
        $lead = Lead::query()->create([
            'student_name' => 'Assign Me',
            'phone' => '919111111111',
            'stage_id' => $stage->id,
        ]);

        Sanctum::actingAs($teamLead);

        $this->postJson('/api/v1/leads/bulk-assign-sales-owner', [
            'lead_ids' => [$lead->id],
            'owner_id' => $ownPsa->id,
        ])->assertOk()->assertJsonPath('count', 1);

        $lead2 = Lead::query()->create([
            'student_name' => 'Reject Me',
            'phone' => '919222222222',
            'stage_id' => $stage->id,
        ]);

        $this->postJson('/api/v1/leads/bulk-assign-sales-owner', [
            'lead_ids' => [$lead2->id],
            'owner_id' => $foreignPsa->id,
        ])->assertStatus(422);
    }

    public function test_team_lead_lead_list_is_capsule_scoped(): void
    {
        $teamLead = $this->user('team_lead');
        $ownPsa = $this->user('psa', ['reporting_manager_id' => $teamLead->id]);
        $otherLead = $this->user('team_lead');
        $foreignPsa = $this->user('psa', ['reporting_manager_id' => $otherLead->id]);

        $stage = $this->salesStage();

        $mine = Lead::query()->create([
            'student_name' => 'MineOwned',
            'phone' => '919333333333',
            'stage_id' => $stage->id,
            'owner_id' => $ownPsa->id,
            'psa_owner_id' => $ownPsa->id,
        ]);
        $unassigned = Lead::query()->create([
            'student_name' => 'UnassignedSales',
            'phone' => '919444444444',
            'stage_id' => $stage->id,
            'owner_id' => null,
        ]);
        $theirs = Lead::query()->create([
            'student_name' => 'OtherCapsule',
            'phone' => '919555555555',
            'stage_id' => $stage->id,
            'owner_id' => $foreignPsa->id,
            'psa_owner_id' => $foreignPsa->id,
        ]);

        Sanctum::actingAs($teamLead);
        $response = $this->getJson('/api/v1/leads');
        $response->assertOk();
        $payload = $response->json('data') ?? $response->json();
        $names = collect(is_array($payload) ? $payload : [])->pluck('student_name')->all();

        $this->assertContains('MineOwned', $names);
        $this->assertContains('UnassignedSales', $names);
        $this->assertNotContains('OtherCapsule', $names);
        $this->assertNotNull($mine->id);
        $this->assertNotNull($unassigned->id);
        $this->assertNotNull($theirs->id);
    }

    public function test_sales_head_sees_all_sales_leads_and_can_manage_any_team_lead(): void
    {
        $salesHead = $this->user('sales_head');
        $teamLead = $this->user('team_lead', ['reporting_manager_id' => $salesHead->id]);
        $psa = $this->user('psa', ['reporting_manager_id' => $teamLead->id]);

        $stage = $this->salesStage();
        Lead::query()->create([
            'student_name' => 'Anywhere',
            'phone' => '919666666666',
            'stage_id' => $stage->id,
            'owner_id' => $psa->id,
            'psa_owner_id' => $psa->id,
        ]);

        Sanctum::actingAs($salesHead);

        $this->getJson('/api/v1/users/'.$teamLead->id)->assertOk();
        $this->patchJson('/api/v1/users/'.$teamLead->id, ['first_name' => 'Updated'])->assertOk();

        $response = $this->getJson('/api/v1/leads');
        $response->assertOk();
        $payload = $response->json('data') ?? $response->json();
        $names = collect(is_array($payload) ? $payload : [])->pluck('student_name')->all();
        $this->assertContains('Anywhere', $names);
    }

    public function test_available_psas_scoped_for_team_lead(): void
    {
        $teamLead = $this->user('team_lead');
        $ownPsa = $this->user('psa', ['reporting_manager_id' => $teamLead->id, 'first_name' => 'Own']);
        $otherLead = $this->user('team_lead');
        $this->user('psa', ['reporting_manager_id' => $otherLead->id, 'first_name' => 'Foreign']);

        Sanctum::actingAs($teamLead);
        $ids = collect($this->getJson('/api/v1/sales-staff/psas')->assertOk()->json())
            ->pluck('id')
            ->all();

        $this->assertContains($ownPsa->id, $ids);
        $this->assertCount(1, $ids);
    }
}
