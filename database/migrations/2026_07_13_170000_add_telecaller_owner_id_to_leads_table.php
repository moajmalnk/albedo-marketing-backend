<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (! Schema::hasColumn('leads', 'telecaller_owner_id')) {
                $table->unsignedBigInteger('telecaller_owner_id')->nullable()->after('owner_id');
                $table->foreign('telecaller_owner_id')->references('id')->on('users')->onDelete('set null');
            }
        });

        // Backfill: if current owner is a telecaller, record them as telecaller_owner_id
        if (Schema::hasColumn('leads', 'telecaller_owner_id') && Schema::hasTable('roles')) {
            $telecallerRoleId = DB::table('roles')->where('key', 'telecaller')->value('id');
            if ($telecallerRoleId) {
                DB::table('leads')
                    ->whereNotNull('owner_id')
                    ->whereNull('telecaller_owner_id')
                    ->whereIn('owner_id', function ($q) use ($telecallerRoleId) {
                        $q->select('id')->from('users')->where('role_id', $telecallerRoleId);
                    })
                    ->update([
                        'telecaller_owner_id' => DB::raw('owner_id'),
                    ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'telecaller_owner_id')) {
                $table->dropForeign(['telecaller_owner_id']);
                $table->dropColumn('telecaller_owner_id');
            }
        });
    }
};
