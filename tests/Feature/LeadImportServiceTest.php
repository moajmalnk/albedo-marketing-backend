<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadStage;
use App\Models\LeadImport;
use App\Models\LeadImportRow;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeadImportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $roleKey = 'admin'): User
    {
        $role = Role::query()->create(['key' => $roleKey, 'name' => ucfirst($roleKey), 'permission_level' => 90]);
        $user = User::query()->create([
            'first_name' => 'Test',
            'email' => fake()->safeEmail(),
            'password_hash' => \Illuminate\Support\Facades\Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        return $user;
    }

    public function test_dry_run_validation(): void
    {
        LeadStage::query()->firstOrCreate(['key' => 'new_lead'], ['label' => 'New Lead']);
        $user = $this->makeUser('admin');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/imports', [
            'rows' => [
                ['name_col' => 'John Doe', 'phone_col' => '9876543210', 'email_col' => 'john@example.com'],
                ['name_col' => '', 'phone_col' => '9876543211', 'email_col' => 'bad_email'], // invalid name/email
            ],
            'file_name' => 'test.csv',
            'source' => 'Meta',
            'duplicate_strategy' => 'skip',
            'duplicate_criteria' => 'phone',
            'mapping_profile' => [
                'student_name' => 'name_col',
                'phone' => 'phone_col',
                'email' => 'email_col',
            ],
            'dry_run' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('total', 2);
        $response->assertJsonPath('valid', 1);
        $response->assertJsonPath('invalid', 1);
    }

    public function test_real_import_with_duplicates_skip(): void
    {
        $stage = LeadStage::query()->firstOrCreate(['key' => 'new_lead'], ['label' => 'New Lead']);
        $user = $this->makeUser('admin');
        Sanctum::actingAs($user);

        // Pre-create existing lead to trigger duplicate checks
        Lead::query()->create([
            'student_name' => 'Existing',
            'phone' => '919876543210',
            'email' => 'existing@example.com',
            'stage_id' => $stage->id,
        ]);

        $response = $this->postJson('/api/v1/imports', [
            'rows' => [
                ['name_col' => 'Existing', 'phone_col' => '9876543210', 'email_col' => 'existing@example.com'], // duplicate
                ['name_col' => 'New Lead', 'phone_col' => '9876543211', 'email_col' => 'new@example.com'], // unique
            ],
            'file_name' => 'test.csv',
            'source' => 'Meta',
            'duplicate_strategy' => 'skip',
            'duplicate_criteria' => 'phone',
            'mapping_profile' => [
                'student_name' => 'name_col',
                'phone' => 'phone_col',
                'email' => 'email_col',
            ],
            'dry_run' => false,
        ]);

        $response->assertStatus(200);
        
        $this->assertDatabaseHas('lead_imports', [
            'file_name' => 'test.csv',
            'total_rows' => 2,
            'accepted_count' => 1,
            'duplicate_count' => 1,
            'status' => 'Completed',
        ]);

        $this->assertDatabaseHas('lead_import_rows', [
            'row_number' => 1,
            'status' => 'duplicate',
        ]);

        $this->assertDatabaseCount('leads', 2);
    }

    public function test_import_marketing_leads_bypasses_auto_assignment(): void
    {
        $stage = LeadStage::query()->firstOrCreate(['key' => 'new_lead'], ['label' => 'New Lead']);
        $user = $this->makeUser('admin');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/imports', [
            'rows' => [
                ['name_col' => 'Marketing Lead 1', 'phone_col' => '9876543220', 'email_col' => 'm1@example.com'],
                ['name_col' => 'Marketing Lead 2', 'phone_col' => '9876543221', 'email_col' => 'm2@example.com'],
            ],
            'file_name' => 'marketing_import.csv',
            'source' => 'Meta',
            'campaign' => 'Marketing Campaign',
            'department' => 'MARKETING',
            'duplicate_strategy' => 'skip',
            'duplicate_criteria' => 'phone',
            'mapping_profile' => [
                'student_name' => 'name_col',
                'phone' => 'phone_col',
                'email' => 'email_col',
            ],
            'dry_run' => false,
        ]);

        $response->assertStatus(200);

        // Both leads should have assigned_dept = 'MARKETING' and no owner
        $leads = Lead::where('assigned_dept', 'MARKETING')->get();
        $this->assertCount(2, $leads);
        foreach ($leads as $lead) {
            $this->assertNull($lead->owner_id);
            $this->assertSame('waiting', $lead->assignment_status);
        }
    }
}
