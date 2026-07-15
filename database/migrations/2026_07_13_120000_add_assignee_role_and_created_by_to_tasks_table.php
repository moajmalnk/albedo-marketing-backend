<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('assignee_role', 20)->nullable()->after('assigned_to');
            $table->unsignedBigInteger('created_by')->nullable()->after('assignee_role');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['assignee_role', 'created_by']);
        });
    }
};
