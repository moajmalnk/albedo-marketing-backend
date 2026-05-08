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
        Schema::create('unknown_calls', function (Blueprint $table) {
            $table->id();
            $table->string('call_id', 80)->nullable();
            $table->enum('direction', ['inbound', 'outbound'])->nullable();
            $table->string('from_phone', 20)->nullable();
            $table->string('to_phone', 20)->nullable();
            $table->string('agent_extension', 20)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->integer('duration_sec')->nullable();
            $table->string('recording_url')->nullable();
            $table->string('disposition', 40)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unknown_calls');
    }
};
