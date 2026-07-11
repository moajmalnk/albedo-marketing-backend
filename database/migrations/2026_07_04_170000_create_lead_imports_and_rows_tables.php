<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('lead_import_rows');
        Schema::dropIfExists('lead_imports');
        Schema::enableForeignKeyConstraints();

        Schema::create('lead_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('file_name');
            $table->string('campaign')->nullable();
            $table->string('source');
            $table->string('department')->nullable();
            $table->integer('total_rows')->default(0);
            $table->integer('accepted_count')->default(0);
            $table->integer('duplicate_count')->default(0);
            $table->integer('rejected_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->string('status')->default('Pending'); // Pending, Queued, Processing, Completed, Failed
            $table->string('error_file_path')->nullable();
            $table->text('notes')->nullable();
            $table->string('duplicate_strategy')->default('skip'); // skip, update, merge, force
            $table->string('duplicate_criteria')->default('phone'); // phone, email, both
            $table->json('mapping_profile')->nullable();
            $table->timestamps();
        });

        Schema::create('lead_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('lead_imports')->onDelete('cascade');
            $table->integer('row_number');
            $table->json('payload')->nullable();
            $table->text('error_message')->nullable();
            $table->string('status'); // failed, rejected, duplicate
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_import_rows');
        Schema::dropIfExists('lead_imports');
    }
};
