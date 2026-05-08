<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

header('Content-Type: text/plain; charset=utf-8');

$token = (string) ($_GET['token'] ?? '');
$expected = (string) env('INIT_APP_TOKEN', 'albedo_launch_2026');

if ($expected === '' || ! hash_equals($expected, $token)) {
    http_response_code(403);
    echo "Forbidden.\n";
    echo "Missing or invalid token.\n";
    exit;
}

$forceKey = ($_GET['force_key'] ?? '0') === '1';
$steps = [];
$hasFailure = false;

$run = static function (string $title, callable $callback) use (&$steps, &$hasFailure): void {
    try {
        $callback();
        $steps[] = "[OK] {$title}";
    } catch (Throwable $e) {
        $hasFailure = true;
        $steps[] = "[FAIL] {$title}: ".$e->getMessage();
    }
};

$run('bootstrap complete', static function (): void {
    // No-op marker step
});

$run('config:clear', function () use ($kernel): void {
    $exit = $kernel->call('config:clear');
    if ($exit !== 0) {
        throw new RuntimeException('config:clear returned non-zero status');
    }
});

$run('config:cache', function () use ($kernel): void {
    $exit = $kernel->call('config:cache');
    if ($exit !== 0) {
        throw new RuntimeException('config:cache returned non-zero status');
    }
});

$run('config:clear (post-cache flush)', function () use ($kernel): void {
    $exit = $kernel->call('config:clear');
    if ($exit !== 0) {
        throw new RuntimeException('config:clear post-cache returned non-zero status');
    }
});

$run('key:generate', function () use ($kernel, $forceKey): void {
    $appKey = (string) config('app.key', '');
    if ($appKey !== '' && ! $forceKey) {
        return;
    }

    $exit = $kernel->call('key:generate', ['--force' => true]);
    if ($exit !== 0) {
        throw new RuntimeException('key:generate returned non-zero status');
    }
});

$run('storage:link', function () use ($kernel): void {
    $exit = $kernel->call('storage:link');
    if ($exit !== 0) {
        throw new RuntimeException('storage:link returned non-zero status');
    }
});

$run('migrate --force', function () use ($kernel): void {
    $exit = $kernel->call('migrate', ['--force' => true]);
    if ($exit !== 0) {
        throw new RuntimeException('migrate returned non-zero status');
    }
});

$run('db:seed --class=RoleSeeder --force', function () use ($kernel): void {
    $exit = $kernel->call('db:seed', ['--class' => 'RoleSeeder', '--force' => true]);
    if ($exit !== 0) {
        throw new RuntimeException('RoleSeeder returned non-zero status');
    }
});

$run('db:seed --class=LeadStageSeeder --force', function () use ($kernel): void {
    $exit = $kernel->call('db:seed', ['--class' => 'LeadStageSeeder', '--force' => true]);
    if ($exit !== 0) {
        throw new RuntimeException('LeadStageSeeder returned non-zero status');
    }
});

$run('db:seed --class=UserSeeder --force', function () use ($kernel): void {
    $exit = $kernel->call('db:seed', ['--class' => 'UserSeeder', '--force' => true]);
    if ($exit !== 0) {
        throw new RuntimeException('UserSeeder returned non-zero status');
    }
});

$run('optimize:clear', function () use ($kernel): void {
    $exit = $kernel->call('optimize:clear');
    if ($exit !== 0) {
        throw new RuntimeException('optimize:clear returned non-zero status');
    }
});

$run('config:clear (final)', function () use ($kernel): void {
    $exit = $kernel->call('config:clear');
    if ($exit !== 0) {
        throw new RuntimeException('final config:clear returned non-zero status');
    }
});

$run('cache:clear (final)', function () use ($kernel): void {
    $exit = $kernel->call('cache:clear');
    if ($exit !== 0) {
        throw new RuntimeException('final cache:clear returned non-zero status');
    }
});

$run('optimize:clear (final)', function () use ($kernel): void {
    $exit = $kernel->call('optimize:clear');
    if ($exit !== 0) {
        throw new RuntimeException('final optimize:clear returned non-zero status');
    }
});

foreach ($steps as $line) {
    echo $line."\n";
}

if ($hasFailure) {
    http_response_code(500);
    echo "Initialization finished with errors.\n";
    exit;
}

echo "Initialization completed successfully.\n";
echo "Attempting self-delete...\n";

if (@unlink(__FILE__)) {
    echo "init_app.php deleted.\n";
} else {
    echo "Self-delete failed. Delete init_app.php manually now.\n";
}
