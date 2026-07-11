<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $role = Role::query()->create([
            'key' => 'telecaller',
            'name' => 'Telecaller',
            'permission_level' => 10,
        ]);

        return User::query()->create([
            'first_name' => 'Shamla',
            'email' => 'shamla@test.com',
            'password_hash' => Hash::make('password'),
            'role_id' => $role->id,
            'department' => 'PM',
            'status' => 'active',
        ]);
    }

    public function test_check_in_break_and_check_out_are_persisted(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->travelTo(Carbon::parse('2026-06-06 09:00:00'));
        $this->postJson('/api/v1/attendance/check-in', ['work_mode' => 'OFFICE'])
            ->assertCreated()
            ->assertJsonPath('session_number', 1)
            ->assertJsonPath('work_mode', 'OFFICE');

        $this->postJson('/api/v1/attendance/check-in', ['work_mode' => 'OFFICE'])
            ->assertOk()
            ->assertJsonPath('session_number', 1);

        $this->assertSame(1, AttendanceLog::query()->where('user_id', $user->id)->count());

        $this->travelTo(Carbon::parse('2026-06-06 09:10:00'));
        $this->postJson('/api/v1/attendance/break/start')->assertOk();

        $this->travelTo(Carbon::parse('2026-06-06 09:20:00'));
        $this->postJson('/api/v1/attendance/break/end')
            ->assertOk()
            ->assertJsonPath('break_seconds', 600);

        $this->travelTo(Carbon::parse('2026-06-06 10:00:00'));
        $this->postJson('/api/v1/attendance/check-out', [
            'finalize' => true,
            'summary' => [
                'leads_handled' => 12,
                'calls_made' => 20,
                'conversions' => 2,
                'followups_completed' => 5,
                'notes' => 'Good day',
                'issues' => '',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('break_seconds', 600)
            ->assertJsonPath('net_minutes', 50)
            ->assertJsonPath('is_final_session', true)
            ->assertJsonPath('summary_leads_handled', 12);

        $this->postJson('/api/v1/attendance/check-in', ['work_mode' => 'OFFICE'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'DAY_ALREADY_FINALIZED');
    }

    public function test_non_final_checkout_allows_next_session_number(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->travelTo(Carbon::parse('2026-06-06 09:00:00'));
        $this->postJson('/api/v1/attendance/check-in', ['work_mode' => 'OFFICE'])->assertCreated();

        $this->travelTo(Carbon::parse('2026-06-06 10:00:00'));
        $this->postJson('/api/v1/attendance/check-out', ['finalize' => false])->assertOk();

        $this->travelTo(Carbon::parse('2026-06-06 11:00:00'));
        $this->postJson('/api/v1/attendance/check-in', ['work_mode' => 'OFFICE'])
            ->assertCreated()
            ->assertJsonPath('session_number', 2);
    }
}
