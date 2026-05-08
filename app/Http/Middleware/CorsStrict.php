<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CorsStrict
{
    public function handle(Request $request, Closure $next)
    {
        $origin = $request->headers->get('Origin');
        $allowedOrigins = $this->allowedOrigins();

        if ($origin && ! in_array($origin, $allowedOrigins, true)) {
            return response()->json(['message' => 'CORS_ORIGIN_NOT_ALLOWED'], 403);
        }

        if ($request->getMethod() === 'OPTIONS') {
            $response = response('', 204);
        } else {
            $response = $next($request);
        }

        if ($origin && in_array($origin, $allowedOrigins, true)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin', false);
            $response->headers->set('Access-Control-Allow-Methods', 'GET,POST,PATCH,PUT,DELETE,OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Authorization,Content-Type,Accept,X-Requested-With,X-XSRF-TOKEN,X-CSRF-TOKEN');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Max-Age', '600');
        }

        return $response;
    }

    private function allowedOrigins(): array
    {
        $env = trim((string) env('CORS_ALLOWED_ORIGINS', 'https://marketing.albedoedu.com'));
        $origins = array_values(array_filter(array_map('trim', explode(',', $env))));
        return $origins !== [] ? $origins : ['https://marketing.albedoedu.com'];
    }
}

