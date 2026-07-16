<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Drop the complex tables
        Schema::dropIfExists('lead_stage_required_fields');
        Schema::dropIfExists('lead_stage_automations');
        Schema::dropIfExists('lead_stage_permissions');

        // 2. Add 'team' to lead_stages
        Schema::table('lead_stages', function (Blueprint $table) {
            if (!Schema::hasColumn('lead_stages', 'team')) {
                $table->enum('team', ['marketing', 'sales', 'both'])->default('marketing')->after('group');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lead_stages', function (Blueprint $table) {
            $table->dropColumn('team');
        });

        // We won't recreate the complex tables in down() as they are considered permanently deprecated,
        // but if needed, one could copy the create statements from the original migration.
    }
};
