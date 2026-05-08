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

class LeadApiTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $roleKey = 'admin'): User
    {
        $role = Role::query()->create(['key' => $roleKey, 'name' => ucfirst($roleKey), 'permission_level' => 90]);
        return User::query()->create([
            'first_name' => 'Test',
            'email' => fake()->safeEmail(),
            'password_hash' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);
    }

    public function test_lead_creation_returns_conflict_for_duplicate_phone(): void
    {
        $stage = LeadStage::query()->create(['key' => 'new_lead', 'label' => 'New Lead']);
        $user = $this->makeUser();
        Lead::query()->create(['student_name' => 'A', 'phone' => '919999999999', 'stage_id' => $stage->id]);

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/v1/leads', ['student_name' => 'B', 'phone' => '9999999999']);
        $response->assertStatus(409);
    }

    public function test_stage_change_creates_transition_record(): void
    {
        $new = LeadStage::query()->create(['key' => 'new_lead', 'label' => 'New Lead', 'order' => 1]);
        $prospect = LeadStage::query()->create(['key' => 'prospect', 'label' => 'Prospect', 'order' => 2]);
        $user = $this->makeUser();
        $lead = Lead::query()->create(['student_name' => 'A', 'phone' => '918888888888', 'stage_id' => $new->id]);

        Sanctum::actingAs($user);
        $response = $this->patchJson("/api/v1/leads/{$lead->id}/stage", ['stage_key' => 'prospect']);
        $response->assertOk()->assertJsonPath('lead.stage_id', $prospect->id);
        $this->assertDatabaseHas('lead_stage_transitions', ['lead_id' => $lead->id, 'to_stage_id' => $prospect->id]);
    }
}
