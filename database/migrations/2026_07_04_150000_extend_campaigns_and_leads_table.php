<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Extend leads table
        Schema::table('leads', function (Blueprint $table) {
            if (!Schema::hasColumn('leads', 'campaign_id')) {
                $table->unsignedBigInteger('campaign_id')->nullable()->after('campaign');
                $table->foreign('campaign_id')->references('id')->on('campaigns')->nullOnDelete();
            }
        });

        // 2. Extend campaigns table
        Schema::table('campaigns', function (Blueprint $table) {
            // Modify status constraint safely (making it string to easily support draft/scheduled/active/paused/completed/archived)
            $table->string('status', 32)->default('active')->change();

            if (!Schema::hasColumn('campaigns', 'owner_id')) {
                $table->unsignedBigInteger('owner_id')->nullable()->after('status');
                $table->foreign('owner_id')->references('id')->on('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('campaigns', 'department')) {
                $table->string('department', 32)->nullable()->after('owner_id');
            }
            if (!Schema::hasColumn('campaigns', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('department');
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('campaigns', 'updated_by')) {
                $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
                $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            }
        });

        // 3. Migrate data
        $campaigns = DB::table('campaigns')->get();
        foreach ($campaigns as $camp) {
            DB::table('leads')
                ->where('campaign', $camp->name)
                ->update(['campaign_id' => $camp->id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['campaign_id']);
            $table->dropColumn('campaign_id');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropColumn(['owner_id', 'department', 'created_by', 'updated_by']);
        });
    }
};
