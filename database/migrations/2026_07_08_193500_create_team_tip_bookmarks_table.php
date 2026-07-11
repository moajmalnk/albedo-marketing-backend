<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_tip_bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_tip_id')->constrained('team_tips')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['team_tip_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_tip_bookmarks');
    }
};
