<?php

namespace App\Http\Middleware;

use App\Models\AttendanceLog;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\Response;

class RequireActiveCheckIn
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user && in_array($user->role?->key, ['telecaller', 'psa'], true)) {
            $open = AttendanceLog::query()
                ->where('user_id', $user->id)
                ->whereDate('day_date', now()->toDateString())
                ->whereNull('check_out_at')
                ->exists();

            if (! $open) {
                throw new HttpException(423, 'CHECK_IN_REQUIRED');
            }
        }

        return $next($request);
    }
}
