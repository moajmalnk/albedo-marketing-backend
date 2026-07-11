<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('leads', 'closed_by')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->unsignedBigInteger('closed_by')->nullable()->after('closed_reason_id');
            });
        }

        if (! Schema::hasColumn('leads', 'closed_at')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->timestamp('closed_at')->nullable()->after('closed_by');
            });
        }
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['closed_by', 'closed_at']);
        });
    }
};
