<?php

use App\Http\Middleware\CorsStrict;
use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\LeadershipOverride;
use App\Http\Middleware\RequireActiveCheckIn;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'leadership.override' => LeadershipOverride::class,
            'active.checkin' => RequireActiveCheckIn::class,
            'force.https' => ForceHttps::class,
            'cors.strict' => CorsStrict::class,
            'whatsapp.worker' => \App\Http\Middleware\WhatsAppWorkerToken::class,
        ]);

        $middleware->appendToGroup('api', [
            ForceHttps::class,
            CorsStrict::class,
        ]);

        $middleware->appendToGroup('web', [
            CorsStrict::class,
        ]);

        $middleware->prependToGroup('api', EnsureFrontendRequestsAreStateful::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], $e->status);
        });

        $exceptions->render(function (HttpException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        });
    })->create();
