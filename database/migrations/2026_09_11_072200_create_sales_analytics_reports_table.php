<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_analytics_reports', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->json('config');
            $table->timestamps();

            $table->index(['owner_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_analytics_reports');
    }
};
