<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lead_stages') || ! Schema::hasTable('lead_stage_permissions')) {
            return;
        }

        $qualifiedStageId = DB::table('lead_stages')->where('key', 'qualified')->value('id');
        if (! $qualifiedStageId) {
            return;
        }

        $existing = DB::table('lead_stage_permissions')
            ->where('lead_stage_id', $qualifiedStageId)
            ->where('role', 'sales_head')
            ->first();

        if ($existing) {
            DB::table('lead_stage_permissions')
                ->where('id', $existing->id)
                ->update(['can_view' => true, 'updated_at' => now()]);
        } else {
            DB::table('lead_stage_permissions')->insert([
                'lead_stage_id' => $qualifiedStageId,
                'role' => 'sales_head',
                'can_view' => true,
                'can_move' => true,
                'can_override' => true,
                'can_close' => true,
                'can_reopen' => true,
                'can_delete' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('lead_stages') || ! Schema::hasTable('lead_stage_permissions')) {
            return;
        }

        $qualifiedStageId = DB::table('lead_stages')->where('key', 'qualified')->value('id');
        if (! $qualifiedStageId) {
            return;
        }

        DB::table('lead_stage_permissions')
            ->where('lead_stage_id', $qualifiedStageId)
            ->where('role', 'sales_head')
            ->update(['can_view' => false, 'updated_at' => now()]);
    }
};
