<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TeamTip;
use App\Models\TeamTipRead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamTipsApiTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $roleKey = 'telecaller', array $overrides = []): User
    {
        $role = Role::query()->firstOrCreate(
            ['key' => $roleKey],
            ['name' => ucfirst(str_replace('_', ' ', $roleKey)), 'permission_level' => 10]
        );

        return User::query()->create(array_merge([
            'first_name' => 'Shamla',
            'email' => $roleKey.'@test.com',
            'password_hash' => Hash::make('password'),
            'role_id' => $role->id,
            'department' => 'PM',
            'status' => 'active',
        ], $overrides));
    }

    private function tip(array $overrides = []): TeamTip
    {
        return TeamTip::query()->create(array_merge([
            'title' => 'Tip',
            'description' => 'Read this.',
            'sent_to' => ['All Telecallers'],
            'sent_by' => 'Admin',
            'sent_by_role' => 'Admin',
            'date_sent' => now()->toDateString(),
            'status' => 'Active',
            'priority' => 'Normal',
            'read_count' => 0,
        ], $overrides));
    }

    public function test_user_sees_only_targeted_active_tips(): void
    {
        $user = $this->user();
        $visible = $this->tip(['title' => 'Visible', 'sent_to' => ['All Telecallers']]);
        $this->tip(['title' => 'Inactive', 'status' => 'Inactive']);
        $this->tip(['title' => 'Marketer', 'sent_to' => ['Marketers']]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/team-tips/my');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $visible->id)
            ->assertJsonPath('0.is_read', false);
    }

    public function test_user_can_mark_visible_tip_as_read_once(): void
    {
        $user = $this->user();
        $tip = $this->tip(['priority' => 'High']);

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/team-tips/{$tip->id}/read")
            ->assertOk()
            ->assertJsonPath('is_read', true);
        $this->postJson("/api/v1/team-tips/{$tip->id}/read")->assertOk();

        $this->assertSame(1, TeamTipRead::query()->where('team_tip_id', $tip->id)->where('user_id', $user->id)->count());
        $this->assertSame(1, $tip->fresh()->read_count);
    }

    public function test_bulk_mark_read_skips_high_priority_tips(): void
    {
        $user = $this->user();
        $normal = $this->tip(['title' => 'Normal', 'priority' => 'Normal']);
        $high = $this->tip(['title' => 'High', 'priority' => 'High']);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/team-tips/read-normal')
            ->assertOk()
            ->assertJsonPath('marked_read', 1);

        $this->assertDatabaseHas('team_tip_reads', ['team_tip_id' => $normal->id, 'user_id' => $user->id]);
        $this->assertDatabaseMissing('team_tip_reads', ['team_tip_id' => $high->id, 'user_id' => $user->id]);
    }
}
