<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = App\Models\User::with('role')->find(2);
$roleKey = $user->role->key ?? '';
$isSalesUser = in_array($roleKey, ['sales_head', 'psa', 'advisor', 'sales'], true);

$assignedDept = 'SALES';
$marketingStageId = App\Models\LeadStage::query()->where('key', 'new_lead')->value('id');
$salesStageId = App\Models\LeadStage::query()->where('team', 'sales')->where('is_active', true)->orderBy('order', 'asc')->value('id');

$targetStageId = ($isSalesUser || $assignedDept === 'SALES') ? ($salesStageId ?? $marketingStageId) : $marketingStageId;

echo "Role: $roleKey, isSalesUser: " . ($isSalesUser ? 'true' : 'false') . "\n";
echo "marketingStageId: $marketingStageId\n";
echo "salesStageId: " . ($salesStageId ?? 'null') . "\n";
echo "targetStageId: $targetStageId\n";
