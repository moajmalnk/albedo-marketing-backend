<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

header('Content-Type: application/json; charset=utf-8');

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

if (($_GET['clear'] ?? '1') === '1') {
    $kernel->call('config:clear');
}

$stateful = config('sanctum.stateful', []);

echo json_encode([
    'config_cleared' => true,
    'sanctum_prefix' => config('sanctum.prefix'),
    'sanctum_path' => config('sanctum.path'),
    'sanctum_stateful' => is_array($stateful) ? array_values(array_filter(array_map('trim', $stateful))) : $stateful,
    'cors_allowed_origins_env' => env('CORS_ALLOWED_ORIGINS'),
    'cors_allowed_origins_effective' => config('cors.allowed_origins'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
