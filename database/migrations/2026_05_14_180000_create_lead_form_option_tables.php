<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_form_option_groups', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('label', 120);
            $table->timestamps();
        });

        Schema::create('lead_form_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('lead_form_option_groups')->cascadeOnDelete();
            $table->string('value', 191);
            $table->string('label', 191);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'value']);
            $table->index(['group_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_form_options');
        Schema::dropIfExists('lead_form_option_groups');
    }
};
