<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_challenges', function (Blueprint $table) {
            $table->id();
            $table->string('category', 191);
            $table->text('description');
            $table->string('department', 64);
            $table->string('reported_by', 120);
            $table->json('affected_leads')->nullable();
            $table->string('status', 32)->default('Open');
            $table->date('date_reported');
            $table->date('date_resolved')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_challenges');
    }
};
