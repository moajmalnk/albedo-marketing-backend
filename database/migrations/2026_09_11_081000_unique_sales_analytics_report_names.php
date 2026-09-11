<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_analytics_reports', function (Blueprint $table) {
            // Composite (owner_id, name) backs the owner_id FK — drop FK before replacing the index.
            $table->dropForeign(['owner_id']);
        });

        Schema::table('sales_analytics_reports', function (Blueprint $table) {
            $table->dropIndex(['owner_id', 'name']);
            $table->unique(['owner_id', 'name']);
            $table->foreign('owner_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_analytics_reports', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
        });

        Schema::table('sales_analytics_reports', function (Blueprint $table) {
            $table->dropUnique(['owner_id', 'name']);
            $table->index(['owner_id', 'name']);
            $table->foreign('owner_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
