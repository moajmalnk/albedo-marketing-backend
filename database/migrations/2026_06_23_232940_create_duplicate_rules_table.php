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
        Schema::create('duplicate_rules', function (Blueprint $table) {
            $table->id();
            $table->boolean('match_phone')->default(true);
            $table->boolean('match_email')->default(true);
            $table->boolean('match_whatsapp')->default(false);
            $table->string('default_action')->default('skip_report');
            $table->string('reengagement_rule')->default('do_nothing');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('duplicate_rules');
    }
};
