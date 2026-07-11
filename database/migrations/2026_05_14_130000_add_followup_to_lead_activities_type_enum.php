<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ENUM_WITH_FOLLOWUP = "ENUM('call', 'whatsapp', 'sms', 'email', 'note', 'assessment', 'meeting', 'followup') NOT NULL";

    private const ENUM_ORIGINAL = "ENUM('call', 'whatsapp', 'sms', 'email', 'note', 'assessment', 'meeting') NOT NULL";

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE lead_activities MODIFY COLUMN type ' . self::ENUM_WITH_FOLLOWUP);
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE lead_activities MODIFY COLUMN type ' . self::ENUM_ORIGINAL);
    }
};
