<?php

use Database\Seeders\LeadStageSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Upsert all pipeline stages so PATCH /leads/{id}/stage never 404s for keys the SPA sends.
     */
    public function up(): void
    {
        (new LeadStageSeeder)->run();
    }

    public function down(): void
    {
        // Do not delete rows — production may reference stage_id.
    }
};
