<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('whatsapp_id', 64)->nullable()->unique()->after('whatsapp');
            $table->foreignId('captured_by_user_id')->nullable()->after('owner_id')->constrained('users')->nullOnDelete();
            $table->timestamp('last_contacted_at')->nullable()->after('next_action_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['captured_by_user_id']);
            $table->dropColumn(['whatsapp_id', 'captured_by_user_id', 'last_contacted_at']);
        });
    }
};
