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

class TeamInsightsAnalyticsTest extends TestCase
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

    public function test_team_insights_forbidden_for_telecaller(): void
    {
        $this->seedStages();
        $user = $this->makeUser('telecaller');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/analytics/team-insights')->assertForbidden();
    }

    public function test_team_insights_ok_for_admin(): void
    {
        $this->seedStages();
        $admin = $this->makeUser('admin');
        $tc = $this->makeUser('telecaller');
        $tc->update(['reporting_manager_id' => null]);

        $newStage = LeadStage::query()->where('key', 'new_lead')->firstOrFail();
        $enrolled = LeadStage::query()->where('key', 'enrolled')->firstOrFail();

        Lead::query()->create([
            'student_name' => 'L1',
            'phone' => '911111111111',
            'stage_id' => $newStage->id,
            'owner_id' => $tc->id,
        ]);
        Lead::query()->create([
            'student_name' => 'L2',
            'phone' => '922222222222',
            'stage_id' => $enrolled->id,
            'owner_id' => $tc->id,
        ]);

        Sanctum::actingAs($admin);
        $res = $this->getJson('/api/v1/analytics/team-insights');
        $res->assertOk();
        $res->assertJsonStructure([
            'from',
            'to',
            'date_cap_applied',
            'qualified_stage_keys',
            'team' => ['telecaller', 'marketer', 'department_head', 'admin'],
            'charts' => ['monthly_trend', 'weekly', 'platform', 'sources'],
        ]);

        $rows = collect($res->json('team.telecaller'))->keyBy('user_id');
        $this->assertSame(2, $rows[(string) $tc->id]['total_leads'] ?? $rows[$tc->id]['total_leads']);
        $this->assertSame(1, $rows[(string) $tc->id]['qualified_leads'] ?? $rows[$tc->id]['qualified_leads']);
    }

    public function test_team_insights_respects_date_range(): void
    {
        $this->seedStages();
        $admin = $this->makeUser('admin');
        $tc = $this->makeUser('telecaller');
        $newStage = LeadStage::query()->where('key', 'new_lead')->firstOrFail();

        $old = now()->subMonths(6);
        $lead = Lead::query()->create([
            'student_name' => 'Old',
            'phone' => '933333333333',
            'stage_id' => $newStage->id,
            'owner_id' => $tc->id,
        ]);
        $lead->timestamps = false;
        $lead->forceFill(['created_at' => $old, 'updated_at' => $old])->save();

        Sanctum::actingAs($admin);
        $from = now()->subDays(7)->toDateString();
        $to = now()->toDateString();
        $res = $this->getJson('/api/v1/analytics/team-insights?from='.$from.'&to='.$to);
        $res->assertOk();

        $rows = collect($res->json('team.telecaller'))->keyBy('user_id');
        $uidKey = (string) $tc->id;
        $this->assertSame(0, $rows[$uidKey]['total_leads'] ?? $rows[(int) $uidKey]['total_leads']);
    }

    public function test_leads_index_attributed_to_user_id(): void
    {
        $this->seedStages();
        $admin = $this->makeUser('admin');
        $marketer = $this->makeUser('marketer');
        $stage = LeadStage::query()->where('key', 'new_lead')->firstOrFail();

        Lead::query()->create([
            'student_name' => 'A',
            'phone' => '944444444444',
            'stage_id' => $stage->id,
            'created_by' => $marketer->id,
        ]);
        Lead::query()->create([
            'student_name' => 'B',
            'phone' => '955555555555',
            'stage_id' => $stage->id,
            'generated_by_user_id' => $marketer->id,
        ]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/leads?attributed_to_user_id='.$marketer->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
