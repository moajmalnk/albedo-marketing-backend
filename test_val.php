<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$validator = app(\App\Services\ValidationService::class);
$errors = $validator->validateRow([
    'student_name' => 'Test',
    'phone' => '1234567890',
    'source_group' => 'Performance Marketing'
]);
print_r($errors);
