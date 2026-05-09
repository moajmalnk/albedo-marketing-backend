<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken as Middleware;

/**
 * CSRF for Sanctum stateful API requests. Login/logout use bearer tokens; exempting them
 * avoids 419 when the SPA and API are on sibling subdomains without a shared session cookie domain.
 * Other stateful POST/PATCH/DELETE routes still require a valid X-XSRF-TOKEN (set SESSION_DOMAIN=.albedoedu.com).
 */
class ValidateCsrfToken extends Middleware
{
    /**
     * @var array<int, string>
     */
    protected $except = [
        'api/v1/auth/login',
        'api/v1/auth/logout',
        'api/v1/webhooks/*',
        'api/v1/telephony/*',
    ];
}
