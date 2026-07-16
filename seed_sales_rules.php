<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\LeadStage;
use App\Models\LeadStageRule;

// Get all active sales stages
$salesStages = LeadStage::where('team', 'sales')->where('is_active', true)->get();

$count = 0;
foreach ($salesStages as $fromStage) {
    foreach ($salesStages as $toStage) {
        if ($fromStage->id === $toStage->id) continue;
        
        // Create rule allowing transition from $fromStage to $toStage
        LeadStageRule::updateOrCreate([
            'from_stage_id' => $fromStage->id,
            'to_stage_id' => $toStage->id,
        ], [
            'is_active' => true,
        ]);
        $count++;
    }
}

echo "Created {$count} allowed transitions between sales stages.\n";
