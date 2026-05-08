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
        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->enum('type', ['call', 'whatsapp', 'sms', 'email', 'note', 'assessment', 'meeting']);
            $table->enum('direction', ['inbound', 'outbound'])->nullable();
            $table->boolean('connected')->nullable();
            $table->string('outcome', 60)->nullable();
            $table->integer('duration_sec')->nullable();
            $table->string('recording_url', 255)->nullable();
            $table->text('comments')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_activities');
    }
};
