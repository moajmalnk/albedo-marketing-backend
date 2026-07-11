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
            $table->string('capture_qualification', 32)->default('qualified')->after('student_name');
        });

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `leads` MODIFY `student_name` VARCHAR(160) NULL');
        } else {
            Schema::table('leads', function (Blueprint $table) {
                $table->string('student_name', 160)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('capture_qualification');
        });

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `leads` MODIFY `student_name` VARCHAR(160) NOT NULL');
        } else {
            Schema::table('leads', function (Blueprint $table) {
                $table->string('student_name', 160)->nullable(false)->change();
            });
        }
    }
};
