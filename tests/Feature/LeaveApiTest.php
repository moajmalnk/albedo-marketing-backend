<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeaveApiTest extends TestCase
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

    public function test_super_admin_can_view_all_leaves(): void
    {
        $admin = $this->user('super_admin');
        $user1 = $this->user('telecaller', ['email' => 't1@test.com', 'department' => 'PM']);
        $user2 = $this->user('telecaller', ['email' => 't2@test.com', 'department' => 'IM']);

        LeaveRequest::query()->create([
            'user_id' => $user1->id,
            'leave_type' => 'Sick Leave',
            'from_date' => '2026-04-10',
            'to_date' => '2026-04-11',
            'total_days' => 2,
            'reason' => 'Fever',
            'status' => 'Pending',
        ]);

        LeaveRequest::query()->create([
            'user_id' => $user2->id,
            'leave_type' => 'Casual Leave',
            'from_date' => '2026-04-12',
            'to_date' => '2026-04-12',
            'total_days' => 1,
            'reason' => 'Personal',
            'status' => 'Approved',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/leaves');

        $response->assertOk()
            ->assertJsonCount(2);
    }

    public function test_dept_head_only_sees_department_telecallers_leaves(): void
    {
        $deptHead = $this->user('dept_head', ['department' => 'PM']);
        $pmTelecaller = $this->user('telecaller', ['email' => 'pm_tc@test.com', 'department' => 'PM']);
        $imTelecaller = $this->user('telecaller', ['email' => 'im_tc@test.com', 'department' => 'IM']);

        $pmLeave = LeaveRequest::query()->create([
            'user_id' => $pmTelecaller->id,
            'leave_type' => 'Sick Leave',
            'from_date' => '2026-04-10',
            'to_date' => '2026-04-11',
            'total_days' => 2,
            'reason' => 'Fever',
        ]);

        $imLeave = LeaveRequest::query()->create([
            'user_id' => $imTelecaller->id,
            'leave_type' => 'Casual Leave',
            'from_date' => '2026-04-12',
            'to_date' => '2026-04-12',
            'total_days' => 1,
            'reason' => 'Personal',
        ]);

        Sanctum::actingAs($deptHead);

        $response = $this->getJson('/api/v1/leaves');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $pmLeave->id);
    }

    public function test_telecaller_can_create_leave_request(): void
    {
        $user = $this->user('telecaller');

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/leaves', [
            'leave_type' => 'Casual Leave',
            'from_date' => '2026-04-15',
            'to_date' => '2026-04-16',
            'total_days' => 2,
            'reason' => 'Going to family function',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('leave_type', 'Casual Leave')
            ->assertJsonPath('status', 'Pending');

        $this->assertDatabaseHas('leave_requests', [
            'user_id' => $user->id,
            'leave_type' => 'Casual Leave',
            'reason' => 'Going to family function',
        ]);
    }

    public function test_admin_can_approve_or_reject_leave(): void
    {
        $admin = $this->user('admin');
        $user = $this->user('telecaller');
        $leave = LeaveRequest::query()->create([
            'user_id' => $user->id,
            'leave_type' => 'Sick Leave',
            'from_date' => '2026-04-10',
            'to_date' => '2026-04-11',
            'total_days' => 2,
            'reason' => 'Fever',
            'status' => 'Pending',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/v1/leaves/{$leave->id}", [
            'status' => 'Approved',
            'admin_comment' => 'Take rest.',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Approved')
            ->assertJsonPath('admin_comment', 'Take rest.');

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leave->id,
            'status' => 'Approved',
            'admin_comment' => 'Take rest.',
        ]);
    }

    public function test_telecaller_can_cancel_pending_leave(): void
    {
        $user = $this->user('telecaller');
        $leave = LeaveRequest::query()->create([
            'user_id' => $user->id,
            'leave_type' => 'Sick Leave',
            'from_date' => '2026-04-10',
            'to_date' => '2026-04-11',
            'total_days' => 2,
            'reason' => 'Fever',
            'status' => 'Pending',
        ]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/v1/leaves/{$leave->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Leave request cancelled successfully.');

        $this->assertSoftDeleted('leave_requests', [
            'id' => $leave->id,
        ]);
    }
}
