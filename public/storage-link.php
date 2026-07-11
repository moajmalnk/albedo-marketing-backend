<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

header('Content-Type: text/plain; charset=utf-8');

$token = $_GET['token'] ?? '';
$expected = env('STORAGE_LINK_TOKEN', '');

if ($expected === '' || !hash_equals($expected, (string) $token)) {
    http_response_code(403);
    echo "Forbidden.\n";
    exit;
}

try {
    $app->make(Kernel::class)->call('storage:link');
    echo "storage:link executed successfully.\n";
    echo "Delete this file after first successful run.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Failed: ".$e->getMessage()."\n";
}

