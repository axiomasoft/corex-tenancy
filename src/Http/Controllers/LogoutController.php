<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Global logout (AC-15): revokes every unrevoked `root_idp_sessions` row of
 * the current identity, cascades into `root_oauth_refresh_tokens` by
 * `session_id`, then ends the tenant-local session. Both tables are
 * audited — this UPDATEs `revoked_at`, it NEVER `DELETE`s a row.
 * `root_idp_sessions` are not created here (that is the AS's job); this
 * controller only ever revokes.
 */
final class LogoutController
{
    public function __construct(
        private readonly string $centralConnection,
    ) {}

    public function logout(Request $request): RedirectResponse
    {
        $identityId = Auth::guard($this->guard())->id();

        if (is_string($identityId) && $identityId !== '') {
            $this->revokeIdentitySessions($identityId);
        }

        Auth::guard($this->guard())->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    private function revokeIdentitySessions(string $identityId): void
    {
        $now = CarbonImmutable::now();

        $sessionIds = DB::connection($this->centralConnection)
            ->table('root_idp_sessions')
            ->where('identity_id', $identityId)
            ->whereNull('revoked_at')
            ->pluck('id');

        if ($sessionIds->isEmpty()) {
            return;
        }

        DB::connection($this->centralConnection)
            ->table('root_idp_sessions')
            ->where('identity_id', $identityId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now]);

        DB::connection($this->centralConnection)
            ->table('root_oauth_refresh_tokens')
            ->whereIn('session_id', $sessionIds)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now]);
    }

    private function guard(): string
    {
        return (string) config('tenancy.oidc.guard', 'web');
    }
}
