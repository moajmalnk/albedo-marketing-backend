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
        Schema::table('enrollments', function (Blueprint $table) {
            $table->index('advisor_id');
            $table->index('lead_id');
            $table->index('admission_status');
            $table->index('created_at');
            $table->index(['advisor_id', 'admission_status', 'created_at'], 'idx_enrollments_advisor_status_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropIndex('idx_enrollments_advisor_status_created');
            $table->dropIndex(['advisor_id']);
            $table->dropIndex(['lead_id']);
            $table->dropIndex(['admission_status']);
            $table->dropIndex(['created_at']);
        });
    }
};
