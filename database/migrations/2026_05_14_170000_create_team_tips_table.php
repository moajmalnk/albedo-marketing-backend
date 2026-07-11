<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_tips', function (Blueprint $table) {
            $table->id();
            $table->string('title', 191);
            $table->text('description');
            $table->json('sent_to');
            $table->string('sent_by', 120);
            $table->string('sent_by_role', 64)->nullable();
            $table->date('date_sent');
            $table->string('status', 16)->default('Active');
            $table->string('priority', 16)->nullable();
            $table->unsignedInteger('read_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_tips');
    }
};
