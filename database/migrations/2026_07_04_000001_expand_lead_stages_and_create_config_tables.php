<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Expand lead_stages table safely
        Schema::table('lead_stages', function (Blueprint $table) {
            if (! Schema::hasColumn('lead_stages', 'type')) {
                $table->enum('type', ['open', 'won', 'lost'])->default('open')->after('group');
            }
            if (! Schema::hasColumn('lead_stages', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('is_terminal');
            }
            if (! Schema::hasColumn('lead_stages', 'description')) {
                $table->text('description')->nullable()->after('is_active');
            }
            if (! Schema::hasColumn('lead_stages', 'owner_role')) {
                $table->string('owner_role', 40)->nullable()->after('description');
            }
            if (! Schema::hasColumn('lead_stages', 'sla_hours')) {
                $table->unsignedInteger('sla_hours')->nullable()->after('owner_role');
            }
            if (! Schema::hasColumn('lead_stages', 'legacy_status')) {
                $table->string('legacy_status', 40)->nullable()->after('sla_hours');
            }
        });

        // 2. Create lead_closed_reasons table
        if (! Schema::hasTable('lead_closed_reasons')) {
            Schema::create('lead_closed_reasons', function (Blueprint $table) {
                $table->id();
                $table->string('key', 40)->unique();
                $table->string('label', 80);
                $table->string('color', 16)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        // 3. Add closed_reason_id to leads table
        if (! Schema::hasColumn('leads', 'closed_reason_id')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->unsignedBigInteger('closed_reason_id')->nullable()->after('stage_id');
                $table->foreign('closed_reason_id')->references('id')->on('lead_closed_reasons')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('leads', 'closed_by')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->unsignedBigInteger('closed_by')->nullable()->after('closed_reason_id');
            });
        }

        if (! Schema::hasColumn('leads', 'closed_at')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->timestamp('closed_at')->nullable()->after('closed_by');
            });
        }
        // 4. Create lead_stage_rules table (allowed transitions)
        if (! Schema::hasTable('lead_stage_rules')) {
            Schema::create('lead_stage_rules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('from_stage_id');
                $table->unsignedBigInteger('to_stage_id');
                $table->string('condition', 255)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('from_stage_id')->references('id')->on('lead_stages')->cascadeOnDelete();
                $table->foreign('to_stage_id')->references('id')->on('lead_stages')->cascadeOnDelete();
                $table->unique(['from_stage_id', 'to_stage_id']);
            });
        }

        // 5. Create lead_stage_permissions table (permission matrix)
        if (! Schema::hasTable('lead_stage_permissions')) {
            Schema::create('lead_stage_permissions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('lead_stage_id');
                $table->string('role', 40);
                $table->boolean('can_view')->default(true);
                $table->boolean('can_move')->default(false);
                $table->boolean('can_override')->default(false);
                $table->boolean('can_close')->default(false);
                $table->boolean('can_reopen')->default(false);
                $table->boolean('can_delete')->default(false);
                $table->timestamps();

                $table->foreign('lead_stage_id')->references('id')->on('lead_stages')->cascadeOnDelete();
                $table->unique(['lead_stage_id', 'role']);
            });
        }

        // 6. Create lead_stage_automations table
        if (! Schema::hasTable('lead_stage_automations')) {
            Schema::create('lead_stage_automations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('lead_stage_id');
                $table->string('action', 40); // assign_role, notify_role, create_task, start_sla, send_email, send_whatsapp
                $table->string('target_role', 40)->nullable();
                $table->string('task_template', 255)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->foreign('lead_stage_id')->references('id')->on('lead_stages')->cascadeOnDelete();
            });
        }

        // 7. Create lead_stage_required_fields table
        if (! Schema::hasTable('lead_stage_required_fields')) {
            Schema::create('lead_stage_required_fields', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('lead_stage_id');
                $table->string('field_name', 80);
                $table->string('field_label', 120);
                $table->boolean('is_required')->default(true);
                $table->timestamps();

                $table->foreign('lead_stage_id')->references('id')->on('lead_stages')->cascadeOnDelete();
                $table->unique(['lead_stage_id', 'field_name']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_stage_required_fields');
        Schema::dropIfExists('lead_stage_automations');
        Schema::dropIfExists('lead_stage_permissions');
        Schema::dropIfExists('lead_stage_rules');

        if (Schema::hasColumn('leads', 'closed_reason_id')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->dropForeign(['closed_reason_id']);
                $table->dropColumn('closed_reason_id');
            });
        }
        if (Schema::hasColumn('leads', 'closed_by')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->dropForeign(['closed_by']);
                $table->dropColumn('closed_by');
            });
        }
        if (Schema::hasColumn('leads', 'closed_at')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->dropColumn('closed_at');
            });
        }

        Schema::dropIfExists('lead_closed_reasons');
    }
};
