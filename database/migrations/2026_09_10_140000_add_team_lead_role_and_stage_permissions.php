<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('roles')) {
            $exists = DB::table('roles')->where('key', 'team_lead')->exists();
            if (! $exists) {
                DB::table('roles')->insert([
                    'key' => 'team_lead',
                    'name' => 'Team Lead',
                    'permission_level' => 55,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('roles')->where('key', 'team_lead')->update([
                    'name' => 'Team Lead',
                    'permission_level' => 55,
                    'updated_at' => now(),
                ]);
            }
        }

        if (! Schema::hasTable('lead_stages') || ! Schema::hasTable('lead_stage_permissions')) {
            return;
        }

        $stages = DB::table('lead_stages')->select('id', 'order', 'owner_role')->get();
        foreach ($stages as $stage) {
            $canView = ((int) $stage->order) >= 3;
            $existing = DB::table('lead_stage_permissions')
                ->where('lead_stage_id', $stage->id)
                ->where('role', 'team_lead')
                ->first();

            $payload = [
                'can_view' => $canView,
                'can_move' => true,
                'can_override' => false,
                'can_close' => true,
                'can_reopen' => false,
                'can_delete' => false,
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('lead_stage_permissions')->where('id', $existing->id)->update($payload);
            } else {
                DB::table('lead_stage_permissions')->insert([
                    'lead_stage_id' => $stage->id,
                    'role' => 'team_lead',
                    ...$payload,
                    'created_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('lead_stage_permissions')) {
            DB::table('lead_stage_permissions')->where('role', 'team_lead')->delete();
        }

        if (Schema::hasTable('roles')) {
            DB::table('roles')->where('key', 'team_lead')->delete();
        }
    }
};
