<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('challenge_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->string('department', 64);
            $table->string('status', 32)->default('Active');
            $table->timestamps();

            $table->unique(['name', 'department']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenge_categories');
    }
};
