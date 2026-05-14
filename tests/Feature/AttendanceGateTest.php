<?php

namespace Tests\Feature;

use App\Models\LeadStage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_telecaller_requires_checkin_for_leads_access(): void
    {
        $role = Role::query()->create(['key' => 'telecaller', 'name' => 'Telecaller', 'permission_level' => 10]);
        $user = User::query()->create([
            'first_name' => 'Tele',
            'email' => 'telecaller@test.com',
            'password_hash' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        LeadStage::query()->where('key', 'new_lead')->firstOrFail();

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/leads');
        $response->assertStatus(423);
    }
}
