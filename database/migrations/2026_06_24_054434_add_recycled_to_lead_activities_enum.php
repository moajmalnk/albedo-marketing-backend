<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ENUM_NEW = "ENUM('call', 'whatsapp', 'sms', 'email', 'note', 'assessment', 'meeting', 'followup', 'recycled', 'stage_change') NOT NULL";
    private const ENUM_OLD = "ENUM('call', 'whatsapp', 'sms', 'email', 'note', 'assessment', 'meeting', 'followup') NOT NULL";

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE lead_activities MODIFY COLUMN type ' . self::ENUM_NEW);
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE lead_activities MODIFY COLUMN type ' . self::ENUM_OLD);
    }
};
