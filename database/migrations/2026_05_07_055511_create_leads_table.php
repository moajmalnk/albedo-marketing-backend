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
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('student_name', 160);
            $table->string('phone', 20)->unique();
            $table->string('whatsapp', 20)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('parent_name', 160)->nullable();
            $table->enum('parent_relation', ['father', 'mother', 'guardian'])->nullable();
            $table->string('class', 20)->nullable();
            $table->enum('syllabus', ['STATE', 'CBSE', 'ICSE', 'IGCSE', 'IB'])->nullable();
            $table->enum('course', ['Foundation', 'Academics', 'Crash', 'Repeater', 'Other'])->nullable();
            $table->json('subjects')->nullable();
            $table->string('school', 160)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('district', 80)->nullable();
            $table->string('state', 80)->nullable();
            $table->string('country', 80)->nullable();
            $table->string('pincode', 12)->nullable();
            $table->enum('source_group', ['influence', 'performance', 'albedo', 'reference', 'other'])->nullable();
            $table->string('source_code', 40)->nullable();
            $table->string('campaign', 120)->nullable();
            $table->unsignedBigInteger('stage_id')->nullable();
            $table->string('status', 40)->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->enum('assigned_dept', ['SALES', 'MARKETING'])->default('SALES');
            $table->boolean('is_read_only')->default(false);
            $table->enum('priority', ['low', 'normal', 'high'])->default('normal');
            $table->boolean('dnd')->default(false);
            $table->timestamp('next_action_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_id', 'stage_id']);
            $table->index(['source_group', 'source_code']);
            $table->index('next_action_at');
            $table->index(['assigned_dept', 'stage_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
