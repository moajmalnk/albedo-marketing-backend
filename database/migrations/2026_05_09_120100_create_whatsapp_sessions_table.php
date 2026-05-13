<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('session_name', 80);
            $table->enum('status', ['DISCONNECTED', 'PAIRING', 'CONNECTED', 'ERROR'])->default('DISCONNECTED');
            $table->string('phone_number', 32)->nullable();
            $table->text('last_qr')->nullable();
            $table->timestamp('last_sync')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'session_name']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_sessions');
    }
};
