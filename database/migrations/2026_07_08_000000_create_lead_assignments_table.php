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
        if (!Schema::hasTable('lead_assignments')) {
            Schema::create('lead_assignments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('lead_id');
                $table->unsignedBigInteger('previous_owner_id')->nullable();
                $table->unsignedBigInteger('new_owner_id')->nullable();
                $table->unsignedBigInteger('assigned_by')->nullable();
                $table->string('assignment_type');
                $table->string('reason')->nullable();
                $table->unsignedBigInteger('department_id')->nullable();
                $table->unsignedBigInteger('campaign_id')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('lead_id')->references('id')->on('leads')->onDelete('cascade');
                $table->foreign('previous_owner_id')->references('id')->on('users')->onDelete('set null');
                $table->foreign('new_owner_id')->references('id')->on('users')->onDelete('set null');
                $table->foreign('assigned_by')->references('id')->on('users')->onDelete('set null');
                $table->foreign('department_id')->references('id')->on('departments')->onDelete('set null');
                $table->foreign('campaign_id')->references('id')->on('campaigns')->onDelete('set null');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_assignments');
    }
};
