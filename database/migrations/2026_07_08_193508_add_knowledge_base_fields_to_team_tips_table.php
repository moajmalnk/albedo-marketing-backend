<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_tips', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->constrained('team_tip_categories')->nullOnDelete();
            $table->string('department', 191)->nullable();
            $table->boolean('pinned')->default(false);
            $table->longText('content')->nullable();
            $table->json('attachments')->nullable();
            $table->softDeletes();
        });

        // Copy description to content
        DB::statement('UPDATE team_tips SET content = description WHERE description IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('team_tips', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn(['category_id', 'department', 'pinned', 'content', 'attachments', 'deleted_at']);
        });
    }
};
