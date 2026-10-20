<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (! Schema::hasColumn('leads', 'lead_source')) {
                $table->string('lead_source', 100)->nullable()->after('pincode');
                $table->index('lead_source');
            }
        });

        // Convert source_group from enum to varchar(64) and source_code to varchar(100)
        // Raw SQL avoids doctrine/dbal requirement for modifying existing columns in MySQL
        DB::statement('ALTER TABLE `leads` MODIFY COLUMN `source_group` VARCHAR(64) NULL');
        DB::statement('ALTER TABLE `leads` MODIFY COLUMN `source_code` VARCHAR(100) NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'lead_source')) {
                $table->dropIndex(['lead_source']);
                $table->dropColumn('lead_source');
            }
        });

        DB::statement("ALTER TABLE `leads` MODIFY COLUMN `source_group` ENUM('influence', 'performance', 'albedo', 'reference', 'other') NULL");
        DB::statement('ALTER TABLE `leads` MODIFY COLUMN `source_code` VARCHAR(40) NULL');
    }
};
