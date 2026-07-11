<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `leads` MODIFY `syllabus` VARCHAR(191) NULL');
            DB::statement('ALTER TABLE `leads` MODIFY `course` VARCHAR(191) NULL');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `leads` MODIFY `syllabus` ENUM('STATE','CBSE','ICSE','IGCSE','IB') NULL");
            DB::statement("ALTER TABLE `leads` MODIFY `course` ENUM('Foundation','Academics','Crash','Repeater','Other') NULL");
        }
    }
};
