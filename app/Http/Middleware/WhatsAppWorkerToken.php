<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WhatsAppWorkerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.whatsapp_worker.token', '');
        $provided = (string) $request->header('X-Whatsapp-Worker-Token', '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'INVALID_WORKER_TOKEN'], 401);
        }

        return $next($request);
    }
}
