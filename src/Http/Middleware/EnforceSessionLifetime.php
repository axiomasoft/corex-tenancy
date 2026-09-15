<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use CoreX\Tenancy\Http\Controllers\OidcLoginController;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * AC-15: a tenant-local session must not outlive
 * `oidc.max_session_lifetime_minutes` (720 = 12h) after the `_corex_auth_at`
 * marker {@see OidcLoginController} sets on login — independent of whatever
 * `session.lifetime` the consuming app configures. A request without the
 * marker (no OIDC login this session — e.g. a guest route) passes through
 * unaffected.
 */
final class EnforceSessionLifetime
{
    /** @return Response|mixed */
    public function handle(Request $request, Closure $next): mixed
    {
        $authAt = $request->session()->get('_corex_auth_at');

        if (! is_string($authAt) || $authAt === '') {
            return $next($request);
        }

        $maxMinutes = (int) config('tenancy.oidc.max_session_lifetime_minutes', 720);

        if (! CarbonImmutable::parse($authAt)->addMinutes($maxMinutes)->isPast()) {
            return $next($request);
        }

        Auth::guard($this->guard())->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        abort(401);
    }

    private function guard(): string
    {
        return (string) config('tenancy.oidc.guard', 'web');
    }
}
