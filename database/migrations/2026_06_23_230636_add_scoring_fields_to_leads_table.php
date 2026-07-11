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
            $table->integer('score')->default(0)->after('stage_id');
            $table->string('score_tier')->nullable()->after('score');
            $table->timestamp('score_last_calculated_at')->nullable()->after('score_tier');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['score', 'score_tier', 'score_last_calculated_at']);
        });
    }
};
