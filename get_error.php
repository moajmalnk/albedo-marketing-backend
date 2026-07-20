<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$row = App\Models\LeadImportRow::where('status', 'failed')->latest()->first();
echo $row ? $row->error_message : 'No error found';
