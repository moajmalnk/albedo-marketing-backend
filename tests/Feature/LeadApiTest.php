<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadStage;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\LeadFormOptionSeeder;
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
        $stage = LeadStage::query()->where('key', 'new_lead')->firstOrFail();
        $user = $this->makeUser();
        Lead::query()->create(['student_name' => 'A', 'phone' => '919999999999', 'stage_id' => $stage->id]);

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/v1/leads', ['student_name' => 'B', 'phone' => '9999999999']);
        $response->assertStatus(409);
    }

    public function test_stage_change_creates_transition_record(): void
    {
        $new = LeadStage::query()->where('key', 'new_lead')->firstOrFail();
        $prospect = LeadStage::query()->where('key', 'prospect')->firstOrFail();
        $user = $this->makeUser();
        $lead = Lead::query()->create(['student_name' => 'A', 'phone' => '918888888888', 'stage_id' => $new->id]);

        Sanctum::actingAs($user);
        $response = $this->patchJson("/api/v1/leads/{$lead->id}/stage", ['stage_key' => 'prospect']);
        $response->assertOk()->assertJsonPath('lead.stage_id', $prospect->id);
        $this->assertDatabaseHas('lead_stage_transitions', ['lead_id' => $lead->id, 'to_stage_id' => $prospect->id]);
    }

    public function test_lead_form_options_returns_grouped_shape(): void
    {
        $this->seed(LeadFormOptionSeeder::class);
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/lead-form-options');
        $response->assertOk();
        $response->assertJsonStructure([
            'connected_by' => [
                '*' => ['id', 'value', 'label', 'sort_order', 'is_active', 'meta'],
            ],
            'source_name',
            'source_code',
            'course',
            'subject',
            'syllabus',
        ]);
        $this->assertNotEmpty($response->json('connected_by'));
    }

    public function test_lead_store_persists_extended_capture_fields(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $payload = [
            'student_name' => 'Extended Lead',
            'phone' => '9122233344',
            'alternate_phone' => '9144455566',
            'whatsapp' => '9122233344',
            'email' => 'ext@example.com',
            'children_count' => 2,
            'already_enrolled' => false,
            'state' => 'Kerala',
            'city' => 'Kochi',
            'country' => 'India',
            'source_group' => 'influence',
            'source_code' => 'NSF_014',
            'campaign' => 'Influence Marketing',
            'connected_by' => 'INBOUND_CALL',
            'enquiry_at' => '2026-05-14T10:30:00Z',
            'notes_html' => '<p>Hello <strong>world</strong></p>',
            'generated_by_user_id' => $user->id,
            'course' => 'Foundation',
            'syllabus' => 'CBSE',
        ];

        $response = $this->postJson('/api/v1/leads', $payload);
        $response->assertCreated()
            ->assertJsonPath('student_name', 'Extended Lead')
            ->assertJsonPath('phone', '919122233344')
            ->assertJsonPath('alternate_phone', '919144455566')
            ->assertJsonPath('connected_by', 'INBOUND_CALL')
            ->assertJsonPath('children_count', 2)
            ->assertJsonPath('generated_by_user_id', $user->id);

        $this->assertDatabaseHas('leads', [
            'student_name' => 'Extended Lead',
            'phone' => '919122233344',
            'connected_by' => 'INBOUND_CALL',
            'children_count' => 2,
            'generated_by_user_id' => $user->id,
            'course' => 'Foundation',
            'syllabus' => 'CBSE',
        ]);
    }

    public function test_lead_store_strips_script_tags_from_notes_html(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/leads', [
            'student_name' => 'Sanitize Notes',
            'phone' => '9133344455',
            'notes_html' => '<script>alert(1)</script><p>ok</p>',
        ])->assertCreated();

        $lead = Lead::query()->where('phone', '919133344455')->first();
        $this->assertNotNull($lead);
        $this->assertStringNotContainsString('<script>', (string) $lead->notes_html);
        $this->assertStringContainsString('<p>ok</p>', (string) $lead->notes_html);
    }

    public function test_lead_form_option_store_forbidden_for_non_admin(): void
    {
        $this->seed(LeadFormOptionSeeder::class);
        $user = $this->makeUser('marketer');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/lead-form-options', [
            'group_slug' => 'connected_by',
            'value' => 'CUSTOM_X',
            'label' => 'Custom',
        ])->assertForbidden();
    }

    public function test_lead_store_rejects_unknown_course_when_picklist_seeded(): void
    {
        $this->seed(LeadFormOptionSeeder::class);
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/leads', [
            'student_name' => 'Bad Course',
            'phone' => '919887766554',
            'course' => 'NOT_A_VALID_COURSE_SLUG',
        ])->assertStatus(422);
    }

    public function test_lead_store_accepts_programme_picklist_values_when_seeded(): void
    {
        $this->seed(LeadFormOptionSeeder::class);
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/leads', [
            'student_name' => 'Programme Lead',
            'phone' => '919776655443',
            'course' => 'A_PLUS_CAMPUS_CBSE',
            'syllabus' => 'STATE',
            'subjects' => ['MATHS', 'PHYSICS'],
        ])->assertCreated();

        $this->assertDatabaseHas('leads', [
            'student_name' => 'Programme Lead',
            'phone' => '919776655443',
            'course' => 'A_PLUS_CAMPUS_CBSE',
            'syllabus' => 'STATE',
        ]);
    }

    public function test_lead_form_options_include_inactive_requires_admin(): void
    {
        $this->seed(LeadFormOptionSeeder::class);
        $user = $this->makeUser('marketer');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/lead-form-options?include_inactive=1')->assertForbidden();
    }

    public function test_lead_form_options_include_inactive_returns_deactivated_rows(): void
    {
        $this->seed(LeadFormOptionSeeder::class);
        $user = $this->makeUser('admin');
        Sanctum::actingAs($user);

        $first = collect($this->getJson('/api/v1/lead-form-options')->json('connected_by'))->first();
        $this->assertIsArray($first);
        $this->assertArrayHasKey('id', $first);
        $id = (int) $first['id'];

        $this->patchJson("/api/v1/lead-form-options/{$id}", ['is_active' => false])->assertOk();

        $activeOnly = collect($this->getJson('/api/v1/lead-form-options')->json('connected_by'));
        $this->assertNull($activeOnly->firstWhere('id', $id));

        $withInactive = collect($this->getJson('/api/v1/lead-form-options?include_inactive=1')->json('connected_by'));
        $row = $withInactive->firstWhere('id', $id);
        $this->assertIsArray($row);
        $this->assertFalse($row['is_active']);
    }
}
