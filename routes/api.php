<?php

use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\LeadActivityController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\LeadImportController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TelephonyWebhookController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

// Temporary probe route to confirm Hostinger receives /api traffic correctly.
Route::any('/sanctum/csrf-cookie-probe', function () {
    return response()->json([
        'ok' => true,
        'path' => request()->path(),
        'origin' => request()->header('Origin'),
    ]);
});

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/webhooks/foxbay/call', [TelephonyWebhookController::class, 'store']);
    Route::post('/telephony/webhook', [TelephonyWebhookController::class, 'store']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
        Route::patch('/users/{user}/status', [UserController::class, 'updateStatus']);
        Route::patch('/users/{user}/password', [UserController::class, 'resetPassword']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);

        Route::post('/attendance/check-in', [AttendanceController::class, 'checkIn']);
        Route::post('/attendance/check-out', [AttendanceController::class, 'checkOut']);
        Route::get('/attendance/today', [AttendanceController::class, 'today']);

        Route::middleware('leadership.override')->group(function (): void {
            Route::middleware('active.checkin')->group(function (): void {
                Route::get('/leads', [LeadController::class, 'index']);
                Route::post('/leads', [LeadController::class, 'store']);
                Route::get('/leads/{lead}', [LeadController::class, 'show']);
                Route::patch('/leads/{lead}', [LeadController::class, 'update']);
                Route::post('/leads/{lead}/assign', [LeadController::class, 'assign']);
                Route::patch('/leads/{lead}/stage', [LeadController::class, 'changeStage']);
                Route::post('/leads/import', [LeadImportController::class, 'store']);

                Route::get('/leads/{lead}/activities', [LeadActivityController::class, 'index']);
                Route::post('/leads/{lead}/activities', [LeadActivityController::class, 'store']);

                Route::get('/tasks', [TaskController::class, 'index']);
                Route::post('/tasks', [TaskController::class, 'store']);
                Route::patch('/tasks/{task}', [TaskController::class, 'update']);
            });
        });

        Route::get('/analytics/productivity', [AnalyticsController::class, 'productivity']);
    });
});
