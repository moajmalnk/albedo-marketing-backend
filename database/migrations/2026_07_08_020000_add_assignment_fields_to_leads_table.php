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
        Schema::table('leads', function (Blueprint $table) {
            if (!Schema::hasColumn('leads', 'assigned_by')) {
                $table->unsignedBigInteger('assigned_by')->nullable()->after('owner_id');
            }
            if (!Schema::hasColumn('leads', 'assigned_at')) {
                $table->timestamp('assigned_at')->nullable()->after('assigned_by');
            }
            if (!Schema::hasColumn('leads', 'assignment_notes')) {
                $table->text('assignment_notes')->nullable()->after('assigned_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'assigned_by')) {
                $table->dropColumn('assigned_by');
            }
            if (Schema::hasColumn('leads', 'assigned_at')) {
                $table->dropColumn('assigned_at');
            }
            if (Schema::hasColumn('leads', 'assignment_notes')) {
                $table->dropColumn('assignment_notes');
            }
        });
    }
};
