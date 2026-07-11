<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the SPA sends a personal-access token, skip Sanctum's "stateful" stack (session + CSRF).
 *
 * Without this, requests from http://localhost:* to a remote API still match SANCTUM_STATEFUL_DOMAINS,
 * so PATCH/DELETE require an XSRF cookie that the browser cannot attach/read cross-site — leading to
 * 419/401 and the frontend treating the user as logged out.
 */
class SkipStatefulSanctumWhenBearerToken
{
    public function __construct(
        protected EnsureFrontendRequestsAreStateful $ensureFrontendRequestsAreStateful,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken()) {
            return $next($request);
        }

        return $this->ensureFrontendRequestsAreStateful->handle($request, $next);
    }
}
