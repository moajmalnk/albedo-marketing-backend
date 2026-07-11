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
            $table->string('course_interested', 191)->nullable()->after('course');
            $table->string('qualification', 191)->nullable()->after('class');
            $table->string('preferred_campus', 191)->nullable()->after('school');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['course_interested', 'qualification', 'preferred_campus']);
        });
    }
};
