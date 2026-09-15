<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Http\Middleware;

use Closure;
use CoreX\Tenancy\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

final class RequireActiveMembership
{
    /** @return Response|mixed */
    public function handle(Request $request, Closure $next): mixed
    {
        $guard = (string) config('tenancy.membership_guard.guard', 'web');
        $identityId = Auth::guard($guard)->id();

        $user = $identityId === null ? null : User::query()->find($identityId);

        if ($identityId !== null && ($user === null || ! $user->is_active)) {
            Auth::guard($guard)->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(403);
        }

        return $next($request);
    }
}
