<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$stages = App\Models\LeadStage::all(['id', 'key', 'team', 'order'])->toArray();
print_r($stages);
