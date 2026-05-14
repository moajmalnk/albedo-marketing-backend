<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceReportTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $key): Role
    {
        return Role::query()->create([
            'key' => $key,
            'name' => ucfirst(str_replace('_', ' ', $key)),
            'permission_level' => 50,
        ]);
    }

    private function user(Role $role, array $attrs = []): User
    {
        return User::query()->create(array_merge([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => Hash::make('password'),
            'role_id' => $role->id,
            'department' => 'PM',
            'status' => 'active',
        ], $attrs));
    }

    public function test_super_admin_can_fetch_attendance_reports(): void
    {
        $saRole = $this->role('super_admin');
        $tcRole = $this->role('telecaller');
        $admin = $this->user($saRole, ['email' => 'sa@test.com', 'department' => 'OPS']);
        $this->user($tcRole, ['department' => 'PM']);

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/attendance/reports?date='.now()->toDateString())->assertOk();
    }

    public function test_admin_can_fetch_attendance_reports(): void
    {
        $adminRole = $this->role('admin');
        $tcRole = $this->role('telecaller');
        $admin = $this->user($adminRole, ['email' => 'admin@test.com', 'department' => 'OPS']);
        $tc = $this->user($tcRole, ['first_name' => 'Tel', 'department' => 'PM']);

        $day = '2026-05-10';
        AttendanceLog::query()->create([
            'user_id' => $tc->id,
            'work_mode' => 'WFH',
            'check_in_at' => $day.' 09:00:00',
            'check_out_at' => null,
            'day_date' => $day,
            'session_number' => 1,
        ]);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/attendance/reports?date='.$day.'&department=PM');

        $response->assertOk()
            ->assertJsonPath('date', $day)
            ->assertJsonPath('stats.roster_total', 1)
            ->assertJsonPath('stats.checked_in', 1)
            ->assertJsonPath('stats.not_checked_in', 0)
            ->assertJsonPath('stats.wfh', 1)
            ->assertJsonPath('stats.on_break', 0);

        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame($tc->id, $rows[0]['user_id']);
        $this->assertSame('WFH', $rows[0]['work_mode']);
    }

    public function test_telecaller_cannot_fetch_reports(): void
    {
        $tcRole = $this->role('telecaller');
        $tc = $this->user($tcRole, ['department' => 'PM']);

        Sanctum::actingAs($tc);
        $this->getJson('/api/v1/attendance/reports')->assertStatus(403);
        $this->getJson('/api/v1/attendance/monthly-summary?year=2026&month=5')->assertStatus(403);
    }

    public function test_dept_head_only_sees_own_department_roster(): void
    {
        $headRole = $this->role('dept_head');
        $tcRole = $this->role('telecaller');
        $head = $this->user($headRole, ['department' => 'PM', 'email' => 'head@test.com']);
        $pmTc = $this->user($tcRole, ['first_name' => 'PMTel', 'department' => 'PM']);
        $imTc = $this->user($tcRole, ['first_name' => 'IMTel', 'department' => 'IM']);

        $day = '2026-05-11';
        Sanctum::actingAs($head);
        $response = $this->getJson('/api/v1/attendance/reports?date='.$day);

        $response->assertOk();
        $ids = collect($response->json('rows'))->pluck('user_id')->all();
        $this->assertContains($pmTc->id, $ids);
        $this->assertNotContains($imTc->id, $ids);
    }

    public function test_late_flag_when_check_in_after_threshold(): void
    {
        Config::set('attendance.late_after', '09:10');

        $adminRole = $this->role('admin');
        $tcRole = $this->role('telecaller');
        $admin = $this->user($adminRole, ['department' => 'OPS']);
        $tc = $this->user($tcRole, ['department' => 'PM']);

        $day = '2026-05-12';
        AttendanceLog::query()->create([
            'user_id' => $tc->id,
            'work_mode' => 'OFFICE',
            'check_in_at' => $day.' 09:15:00',
            'check_out_at' => null,
            'day_date' => $day,
            'session_number' => 1,
        ]);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/attendance/reports?date='.$day.'&department=PM');

        $response->assertOk();
        $row = collect($response->json('rows'))->firstWhere('user_id', $tc->id);
        $this->assertNotNull($row);
        $this->assertTrue($row['is_late']);
        $this->assertSame(1, $response->json('stats.late'));
    }

    public function test_monthly_summary_returns_shape(): void
    {
        $adminRole = $this->role('admin');
        $tcRole = $this->role('telecaller');
        $admin = $this->user($adminRole, ['department' => 'OPS']);
        $tc = $this->user($tcRole, ['department' => 'PM']);

        AttendanceLog::query()->create([
            'user_id' => $tc->id,
            'work_mode' => 'OFFICE',
            'check_in_at' => '2026-05-05 09:00:00',
            'check_out_at' => '2026-05-05 18:00:00',
            'net_minutes' => 540,
            'day_date' => '2026-05-05',
            'session_number' => 1,
        ]);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/attendance/monthly-summary?year=2026&month=5&department=PM');

        $response->assertOk()
            ->assertJsonPath('year', 2026)
            ->assertJsonPath('month', 5)
            ->assertJsonStructure([
                'working_days_present',
                'business_days_in_month',
                'avg_check_in',
                'avg_check_out',
                'avg_hours_per_day',
            ]);
        $this->assertGreaterThan(0, $response->json('business_days_in_month'));
    }
}
