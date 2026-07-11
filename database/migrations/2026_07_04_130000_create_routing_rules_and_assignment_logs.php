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
        // 1. Extend leads table
        Schema::table('leads', function (Blueprint $table) {
            if (!Schema::hasColumn('leads', 'assignment_status')) {
                $table->enum('assignment_status', [
                    'waiting',
                    'assigned',
                    'auto_assigned',
                    'manual_assigned',
                    'failed',
                    'retry_pending',
                    'cancelled'
                ])->default('waiting')->after('owner_id');
            }
            if (!Schema::hasColumn('leads', 'routing_failed')) {
                $table->boolean('routing_failed')->default(false)->after('assignment_status');
            }
        });

        // 2. Extend users table
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'max_capacity')) {
                $table->integer('max_capacity')->default(50)->after('status');
            }
            if (!Schema::hasColumn('users', 'availability')) {
                $table->boolean('availability')->default(true)->after('max_capacity');
            }
            if (!Schema::hasColumn('users', 'is_online')) {
                $table->boolean('is_online')->default(false)->after('availability');
            }
        });

        // 3. Create lead_routing_rules table
        if (!Schema::hasTable('lead_routing_rules')) {
            Schema::create('lead_routing_rules', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->integer('priority')->default(0);
                $table->boolean('status')->default(true); // enabled/disabled
                $table->json('conditions')->nullable();
                $table->string('assignment_method'); // round_robin, least_loaded, capacity_based, specific_user, specific_team, highest_performer, random
                $table->string('destination')->nullable();
                $table->string('fallback')->nullable();
                $table->timestamp('last_executed_at')->nullable();
                $table->integer('successful_assignments')->default(0);
                $table->integer('failed_assignments')->default(0);
                $table->timestamps();
            });
        }

        // 4. Create lead_assignment_logs table
        if (!Schema::hasTable('lead_assignment_logs')) {
            Schema::create('lead_assignment_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('lead_id');
                $table->unsignedBigInteger('old_owner_id')->nullable();
                $table->unsignedBigInteger('new_owner_id')->nullable();
                $table->string('assignment_type'); // auto, manual, rule
                $table->string('reason')->nullable();
                $table->unsignedBigInteger('assigned_by')->nullable();
                $table->timestamps();

                $table->foreign('lead_id')->references('id')->on('leads')->onDelete('cascade');
                $table->foreign('old_owner_id')->references('id')->on('users')->onDelete('set null');
                $table->foreign('new_owner_id')->references('id')->on('users')->onDelete('set null');
                $table->foreign('assigned_by')->references('id')->on('users')->onDelete('set null');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_assignment_logs');
        Schema::dropIfExists('lead_routing_rules');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['max_capacity', 'availability', 'is_online']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['assignment_status', 'routing_failed']);
        });
    }
};
