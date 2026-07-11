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
        Schema::create('lead_imports', function (Blueprint $table) {
            $table->id();
            $table->string('file_name');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('source');
            $table->integer('total_rows')->default(0);
            $table->integer('accepted_count')->default(0);
            $table->integer('rejected_count')->default(0);
            $table->integer('duplicate_count')->default(0);
            $table->string('status')->default('Processing');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_imports');
    }
};
