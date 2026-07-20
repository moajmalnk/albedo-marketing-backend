<?php

use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/sanctum/csrf-cookie', [CsrfCookieController::class, 'show']);

Route::get('/clear-opcache', function () {
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }
    return 'OPcache cleared';
});
