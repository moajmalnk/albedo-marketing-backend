<?php

namespace Tests\Feature;

use App\Models\WfhRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WfhRequestApiTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $roleKey = 'telecaller', array $overrides = []): User
    {
        $role = Role::query()->firstOrCreate(
            ['key' => $roleKey],
            ['name' => ucfirst(str_replace('_', ' ', $roleKey)), 'permission_level' => 10]
        );

        return User::query()->create(array_merge([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $roleKey.'@test.com',
            'password_hash' => Hash::make('password'),
            'role_id' => $role->id,
            'department' => 'PM',
            'status' => 'active',
        ], $overrides));
    }

    public function test_super_admin_can_view_all_wfh_requests(): void
    {
        $admin = $this->user('super_admin');
        $user1 = $this->user('telecaller', ['email' => 't1@test.com', 'department' => 'PM']);
        $user2 = $this->user('telecaller', ['email' => 't2@test.com', 'department' => 'IM']);

        WfhRequest::query()->create([
            'user_id' => $user1->id,
            'from_date' => '2025-04-10',
            'to_date' => '2025-04-10',
            'reason' => 'Internet issue',
            'status' => 'Pending',
        ]);

        WfhRequest::query()->create([
            'user_id' => $user2->id,
            'from_date' => '2025-04-12',
            'to_date' => '2025-04-12',
            'reason' => 'Focus day',
            'status' => 'Approved',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/wfh-requests');

        $response->assertOk()
            ->assertJsonCount(2);
    }

    public function test_dept_head_only_sees_department_telecallers_wfh_requests(): void
    {
        $deptHead = $this->user('dept_head', ['department' => 'PM']);
        $pmTelecaller = $this->user('telecaller', ['email' => 'pm_tc@test.com', 'department' => 'PM']);
        $imTelecaller = $this->user('telecaller', ['email' => 'im_tc@test.com', 'department' => 'IM']);

        $pmWfh = WfhRequest::query()->create([
            'user_id' => $pmTelecaller->id,
            'from_date' => '2025-04-10',
            'to_date' => '2025-04-10',
            'reason' => 'Internet issue',
        ]);

        $imWfh = WfhRequest::query()->create([
            'user_id' => $imTelecaller->id,
            'from_date' => '2025-04-12',
            'to_date' => '2025-04-12',
            'reason' => 'Focus day',
        ]);

        Sanctum::actingAs($deptHead);

        $response = $this->getJson('/api/v1/wfh-requests');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $pmWfh->id);
    }

    public function test_telecaller_can_create_wfh_request(): void
    {
        $user = $this->user('telecaller');

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/wfh-requests', [
            'from_date' => '2025-04-15',
            'to_date' => '2025-04-16',
            'reason' => 'Need focused environment',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('reason', 'Need focused environment')
            ->assertJsonPath('status', 'Pending');

        $this->assertDatabaseHas('wfh_requests', [
            'user_id' => $user->id,
            'reason' => 'Need focused environment',
        ]);
    }

    public function test_admin_can_approve_or_reject_wfh_request(): void
    {
        $admin = $this->user('admin');
        $user = $this->user('telecaller');
        $wfh = WfhRequest::query()->create([
            'user_id' => $user->id,
            'from_date' => '2025-04-10',
            'to_date' => '2025-04-10',
            'reason' => 'Fever recovery',
            'status' => 'Pending',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/v1/wfh-requests/{$wfh->id}", [
            'status' => 'Approved',
            'admin_note' => 'Approved. Stay safe.',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Approved')
            ->assertJsonPath('admin_note', 'Approved. Stay safe.');

        $this->assertDatabaseHas('wfh_requests', [
            'id' => $wfh->id,
            'status' => 'Approved',
            'admin_note' => 'Approved. Stay safe.',
        ]);
    }

    public function test_telecaller_can_cancel_pending_wfh_request(): void
    {
        $user = $this->user('telecaller');
        $wfh = WfhRequest::query()->create([
            'user_id' => $user->id,
            'from_date' => '2025-04-10',
            'to_date' => '2025-04-10',
            'reason' => 'Internet out',
            'status' => 'Pending',
        ]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/v1/wfh-requests/{$wfh->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'WFH request cancelled successfully.');

        $this->assertSoftDeleted('wfh_requests', [
            'id' => $wfh->id,
        ]);
    }
}
