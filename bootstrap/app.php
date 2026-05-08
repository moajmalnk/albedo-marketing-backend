<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'leadership.override' => \App\Http\Middleware\LeadershipOverride::class,
            'active.checkin' => \App\Http\Middleware\RequireActiveCheckIn::class,
            'force.https' => \App\Http\Middleware\ForceHttps::class,
            'cors.strict' => \App\Http\Middleware\CorsStrict::class,
        ]);

        $middleware->appendToGroup('api', [
            \App\Http\Middleware\ForceHttps::class,
            \App\Http\Middleware\CorsStrict::class,
        ]);

        $middleware->prependToGroup('api', EnsureFrontendRequestsAreStateful::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], $e->status);
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        });
    })->create();
