<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$latestImport = App\Models\LeadImport::latest('id')->first();
if ($latestImport) {
    echo "Latest import ID: " . $latestImport->id . "\n";
    echo "User ID: " . $latestImport->user_id . "\n";
    
    $user = App\Models\User::with('role')->find($latestImport->user_id);
    echo "User Role: " . ($user->role->key ?? 'None') . "\n";
} else {
    echo "No imports found.\n";
}

$latestLead = App\Models\Lead::latest('id')->first();
if ($latestLead) {
    echo "Latest Lead Stage: " . $latestLead->stage_id . "\n";
    echo "Latest Lead Status: " . $latestLead->status . "\n";
}
