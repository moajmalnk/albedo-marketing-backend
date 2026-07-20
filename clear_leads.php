<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

try {
    Schema::disableForeignKeyConstraints();
    
    // Clear child tables first to be clean
    DB::table('lead_activities')->truncate();
    DB::table('lead_stage_transitions')->truncate();
    DB::table('lead_assignments')->truncate();
    DB::table('lead_documents')->truncate();
    
    // Clear leads table
    DB::table('leads')->truncate();
    
    Schema::enableForeignKeyConstraints();
    
    echo "Successfully cleared all leads and related records from the database.\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
