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
        Schema::table('leads', function (Blueprint $table) {
            if (!Schema::hasColumn('leads', 'psa_owner_id')) {
                $table->unsignedBigInteger('psa_owner_id')->nullable()->after('owner_id');
                $table->foreign('psa_owner_id')->references('id')->on('users')->onDelete('set null');
            }
            if (!Schema::hasColumn('leads', 'advisor_owner_id')) {
                $table->unsignedBigInteger('advisor_owner_id')->nullable()->after('psa_owner_id');
                $table->foreign('advisor_owner_id')->references('id')->on('users')->onDelete('set null');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['psa_owner_id']);
            $table->dropForeign(['advisor_owner_id']);
            $table->dropColumn(['psa_owner_id', 'advisor_owner_id']);
        });
    }
};
