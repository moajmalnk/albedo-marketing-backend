<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unknown_calls', function (Blueprint $table) {
            $table->enum('status', ['open', 'linked', 'ignored'])->default('open')->after('disposition');
            $table->foreignId('linked_lead_id')->nullable()->after('status')->constrained('leads')->nullOnDelete();
            $table->foreignId('linked_by_user_id')->nullable()->after('linked_lead_id')->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('linked_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('unknown_calls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('linked_lead_id');
            $table->dropConstrainedForeignId('linked_by_user_id');
            $table->dropColumn(['status', 'resolved_at']);
        });
    }
};
