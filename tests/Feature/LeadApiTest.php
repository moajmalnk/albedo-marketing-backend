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
        $user = User::query()->create([
            'first_name' => 'Test',
            'email' => fake()->safeEmail(),
            'password_hash' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        if (in_array($roleKey, ['telecaller', 'psa', 'marketer', 'advisor'], true)) {
            \App\Models\AttendanceLog::query()->create([
                'user_id' => $user->id,
                'work_mode' => 'OFFICE',
                'check_in_at' => now(),
                'day_date' => now()->toDateString(),
                'session_number' => 1,
            ]);
        }

        return $user;
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
        $assigned = LeadStage::query()->where('key', 'assigned_telecaller')->firstOrFail();
        $user = $this->makeUser();
        $lead = Lead::query()->create(['student_name' => 'A', 'phone' => '918888888888', 'stage_id' => $new->id]);

        Sanctum::actingAs($user);
        $response = $this->patchJson("/api/v1/leads/{$lead->id}/stage", ['stage_key' => 'assigned_telecaller']);
        $response->assertOk()->assertJsonPath('lead.stage_id', $assigned->id);
        $this->assertDatabaseHas('lead_stage_transitions', ['lead_id' => $lead->id, 'to_stage_id' => $assigned->id]);
    }

    public function test_admin_can_jump_multiple_linear_stages(): void
    {
        $new = LeadStage::query()->where('key', 'new_lead')->firstOrFail();
        $enrolled = LeadStage::query()->where('key', 'enrolled')->firstOrFail();
        $user = $this->makeUser('admin');
        $lead = Lead::query()->create(['student_name' => 'Jump', 'phone' => '917777777777', 'stage_id' => $new->id]);

        Sanctum::actingAs($user);
        $response = $this->patchJson("/api/v1/leads/{$lead->id}/stage", ['stage_key' => 'enrolled']);
        $response->assertOk()->assertJsonPath('lead.stage_id', $enrolled->id);
    }

    public function test_marketer_cannot_skip_linear_stages(): void
    {
        $new = LeadStage::query()->where('key', 'new_lead')->firstOrFail();
        $user = $this->makeUser('marketer');
        $lead = Lead::query()->create(['student_name' => 'NoSkip', 'phone' => '916666666666', 'stage_id' => $new->id]);

        Sanctum::actingAs($user);
        $response = $this->patchJson("/api/v1/leads/{$lead->id}/stage", ['stage_key' => 'enrolled']);
        $this->assertTrue(in_array($response->status(), [403, 422], true));
        $this->assertSame($new->id, $lead->fresh()->stage_id);
    }

    public function test_lead_index_filters_by_q(): void
    {
        $stage = LeadStage::query()->where('key', 'new_lead')->firstOrFail();
        $user = $this->makeUser();
        Lead::query()->create(['student_name' => 'UniqueAlphaBeta', 'phone' => '911111111111', 'stage_id' => $stage->id]);
        Lead::query()->create(['student_name' => 'OtherPerson', 'phone' => '922222222222', 'stage_id' => $stage->id]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/leads?q='.urlencode('UniqueAlpha').'&limit=50');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertIsArray($data);
        $names = array_column($data, 'student_name');
        $this->assertContains('UniqueAlphaBeta', $names);
        $this->assertNotContains('OtherPerson', $names);
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
            'capture_qualification' => 'qualified',
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

    public function test_lead_form_options_include_inactive_requires_privileged_role(): void
    {
        $this->seed(LeadFormOptionSeeder::class);
        $user = $this->makeUser('marketer');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/lead-form-options?include_inactive=1')->assertForbidden();
    }

    public function test_dept_head_can_access_lead_form_options_with_include_inactive(): void
    {
        $this->seed(LeadFormOptionSeeder::class);
        $user = $this->makeUser('dept_head');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/lead-form-options?include_inactive=1')->assertOk();
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

    public function test_lead_store_requires_student_name_when_qualified(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/leads', [
            'capture_qualification' => 'qualified',
            'phone' => '918887776665',
        ])->assertStatus(422);
    }

    public function test_lead_store_not_qualified_allows_null_student_name(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $payload = [
            'capture_qualification' => 'not_qualified',
            'phone' => '917778889990',
            'alternate_phone' => '917778889991',
            'whatsapp' => '917778889990',
            'email' => 'nq@example.com',
            'already_enrolled' => false,
            'state' => 'Kerala',
            'city' => 'Kochi',
            'country' => 'India',
            'source_group' => 'influence',
            'source_code' => 'NSF_014',
            'campaign' => 'Influence Marketing',
            'connected_by' => 'INBOUND_CALL',
            'enquiry_at' => '2026-05-14T10:30:00Z',
            'notes_html' => '<p>NQ</p>',
            'generated_by_user_id' => $user->id,
            'course' => 'Foundation',
            'syllabus' => 'CBSE',
        ];

        $response = $this->postJson('/api/v1/leads', $payload);
        $response->assertCreated()
            ->assertJsonPath('capture_qualification', 'not_qualified')
            ->assertJsonPath('phone', '917778889990');

        $this->assertDatabaseHas('leads', [
            'phone' => '917778889990',
            'capture_qualification' => 'not_qualified',
        ]);

        $lead = Lead::query()->where('phone', '917778889990')->first();
        $this->assertNotNull($lead);
        $this->assertNull($lead->student_name);
    }

    public function test_sales_head_create_defaults_to_qualified_stage(): void
    {
        $qualified = LeadStage::query()->where('key', 'qualified')->firstOrFail();
        $user = $this->makeUser('sales_head');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/leads', [
            'student_name' => 'Sales Head Direct',
            'phone' => '915551112233',
            'assigned_dept' => 'SALES',
        ]);

        $response->assertCreated()
            ->assertJsonPath('stage.key', 'qualified')
            ->assertJsonPath('stage_id', $qualified->id)
            ->assertJsonPath('status', $qualified->legacy_status ?? 'Qualified');
    }

    public function test_admin_create_defaults_to_new_lead_stage(): void
    {
        $new = LeadStage::query()->where('key', 'new_lead')->firstOrFail();
        $user = $this->makeUser('admin');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/leads', [
            'student_name' => 'Admin Created',
            'phone' => '915554445566',
        ]);

        $response->assertCreated()
            ->assertJsonPath('stage.key', 'new_lead')
            ->assertJsonPath('stage_id', $new->id);
    }

    public function test_sales_head_can_list_qualified_leads(): void
    {
        $qualified = LeadStage::query()->where('key', 'qualified')->firstOrFail();
        $new = LeadStage::query()->where('key', 'new_lead')->firstOrFail();
        $user = $this->makeUser('sales_head');

        Lead::query()->create([
            'student_name' => 'VisibleQualified',
            'phone' => '915550001111',
            'stage_id' => $qualified->id,
            'status' => 'Qualified',
        ]);
        Lead::query()->create([
            'student_name' => 'HiddenNewLead',
            'phone' => '915550002222',
            'stage_id' => $new->id,
            'status' => 'New',
        ]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/leads?limit=50');
        $response->assertOk();

        $names = array_column($response->json('data') ?? [], 'student_name');
        $this->assertContains('VisibleQualified', $names);
        $this->assertNotContains('HiddenNewLead', $names);
    }

    public function test_lead_history_returns_audit_and_activity_timeline(): void
    {
        $new = LeadStage::query()->where('key', 'new_lead')->firstOrFail();
        $assigned = LeadStage::query()->where('key', 'assigned_telecaller')->firstOrFail();
        $user = $this->makeUser();
        $lead = Lead::query()->create(['student_name' => 'History Lead', 'phone' => '919000000001', 'stage_id' => $new->id]);

        Sanctum::actingAs($user);
        $this->patchJson("/api/v1/leads/{$lead->id}/stage", ['stage_key' => 'assigned_telecaller'])->assertOk();

        $this->postJson("/api/v1/leads/{$lead->id}/activities", [
            'type' => 'note',
            'comments' => 'CRM note for history',
        ])->assertCreated();

        $res = $this->getJson("/api/v1/leads/{$lead->id}/history");
        $res->assertOk()
            ->assertJsonPath('lead_id', $lead->id);

        $items = $res->json('items');
        $this->assertIsArray($items);
        $this->assertNotEmpty($items);

        $kinds = collect($items)->pluck('kind')->all();
        $this->assertContains('audit', $kinds);
        $this->assertContains('activity', $kinds);

        $stageRow = collect($items)->first(fn (array $i) => ($i['action'] ?? '') === 'lead.stage_change');
        $this->assertIsArray($stageRow);
        $this->assertStringContainsString('->', (string) ($stageRow['description'] ?? ''));

        $noteRow = collect($items)->first(fn (array $i) => ($i['kind'] ?? '') === 'activity' && ($i['activity_type'] ?? '') === 'note');
        $this->assertIsArray($noteRow);
        $this->assertSame($user->id, $noteRow['actor']['id'] ?? null);
    }

    public function test_marketing_leads_bypass_auto_assignment(): void
    {
        $user = $this->makeUser('telecaller');
        Sanctum::actingAs($user);
        
        // Create a lead with assigned_dept = 'MARKETING'
        $response = $this->postJson('/api/v1/leads', [
            'student_name' => 'Marketing Prospect',
            'phone' => '9999988888',
            'assigned_dept' => 'MARKETING',
        ]);
        
        $response->assertCreated();
        $lead = Lead::findOrFail($response->json('id'));
        
        // It must NOT be auto-assigned to any user and land in waiting state
        $this->assertNull($lead->owner_id);
        $this->assertSame('waiting', $lead->assignment_status);
        $this->assertFalse((bool)$lead->routing_failed);

        // 2. Create a lead with assigned_dept = 'SALES' (default or explicit)
        $response2 = $this->postJson('/api/v1/leads', [
            'student_name' => 'Sales Prospect',
            'phone' => '9999977777',
            'assigned_dept' => 'SALES',
        ]);
        
        $response2->assertCreated();
        $lead2 = Lead::findOrFail($response2->json('id'));
        
        // All leads now land in manual assignment queue
        $this->assertNull($lead2->owner_id);
        $this->assertSame('waiting', $lead2->assignment_status);
    }
}
