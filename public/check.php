<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';

$appEnv = (string) env('APP_ENV', 'unknown');
$dbConnection = (string) env('DB_CONNECTION', 'unknown');
$corsRaw = (string) env('CORS_ALLOWED_ORIGINS', '');
$corsOrigins = array_values(array_filter(array_map('trim', explode(',', $corsRaw))));

$dbStatus = [
    'ok' => false,
    'message' => 'not tested',
];

try {
    /** @var \Illuminate\Contracts\Console\Kernel $kernel */
    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    /** @var \Illuminate\Database\DatabaseManager $db */
    $db = $app->make('db');
    $db->connection()->getPdo();

    $dbStatus = [
        'ok' => true,
        'message' => 'connected',
    ];
} catch (Throwable $e) {
    $dbStatus = [
        'ok' => false,
        'message' => $e->getMessage(),
    ];
}

echo json_encode([
    'app_env' => $appEnv,
    'db_connection' => $dbConnection,
    'db_status' => $dbStatus,
    'cors_allowed_origins' => $corsOrigins,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
