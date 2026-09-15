<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Impersonation;

use Carbon\CarbonImmutable;
use CoreX\Audit\CurrentActor;
use CoreX\Tenancy\Contracts\ImpersonationService;
use CoreX\Tenancy\Events\ImpersonationEnded;
use CoreX\Tenancy\Events\ImpersonationStarted;
use CoreX\Tenancy\Exceptions\OidcAuthenticationException;
use CoreX\Tenancy\ImpersonationState;
use CoreX\Tenancy\Models\Account;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Override;

/**
 * Cloud implementation of support impersonation (B-11 §5.9, D139). Rebound
 * over the boxed `NullImpersonationService` in `TenancyServiceProvider`
 * under `tenancy.oidc.impersonation.enabled` (D10). `start()`/`stop()`
 * follow research/12 §4 EXACTLY — atomic claim → dual-log mirror BEFORE the
 * session opens (D146) → session state + login; `stop()` reverses the
 * mirror order (session first) because the tenant session, not the audit
 * mirror, is the safety-critical half of the pair.
 *
 * @internal spec: B-11 §5.9, D139/D140/D143/D144/D146
 */
final class DatabaseImpersonationService implements ImpersonationService
{
    private const string SESSION_GRANT_ID = 'corex.impersonation.grant_id';

    private const string SESSION_STAFF_ID = 'corex.impersonation.staff_identity_id';

    private const string SESSION_TARGET_ID = 'corex.impersonation.target_user_id';

    private const string SESSION_EXPIRES_AT = 'corex.impersonation.expires_at';

    /**
     * Request-scoped memo (D139 docblock) — this service is bound as a
     * scoped service, so the container gives one instance per request; `state()`
     * reads the session ONCE and caches the result (or its absence) instead
     * of re-parsing on every {@see CurrentActor} call.
     */
    private bool $resolved = false;

    private ?ImpersonationState $memoState = null;

    #[Override]
    public function start(string $grantId, string $staffIdentityId): void
    {
        $central = $this->centralConnection();

        /** @var Account $account */
        $account = tenancy()->tenant;

        $requireApprovedBy = (bool) config('tenancy.oidc.impersonation.require_approved_by', false);

        // D143 — ONE atomic UPDATE: one-time-use, TTL, revocation, staff
        // presenter, non-empty reason, (optional) 4-eyes, and `is_staff` all
        // gate the SAME statement via the EXISTS subquery. affected === 1 is
        // the ONLY success signal; every other outcome renders the same
        // 403 (no detail leaked about which condition failed).
        $affected = DB::connection($central)->table('root_impersonation_grants')
            ->where('id', $grantId)
            ->where('staff_identity_id', $staffIdentityId)
            ->where('account_id', $account->id)
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->where('reason', '<>', '')
            ->when($requireApprovedBy, static fn ($query) => $query->whereNotNull('approved_by'))
            ->whereExists(static function ($query) use ($staffIdentityId): void {
                $query->selectRaw('1')
                    ->from('root_identities')
                    ->where('id', $staffIdentityId)
                    ->where('is_staff', true);
            })
            ->update(['used_at' => now(), 'updated_at' => now()]);

        if ($affected !== 1) {
            throw OidcAuthenticationException::impersonationDenied();
        }

        $row = DB::connection($central)->table('root_impersonation_grants')->where('id', $grantId)->first();

        $targetUserId = $row->target_user_id ?? $account->owner_identity_id;

        if (! is_string($targetUserId) || $targetUserId === '') {
            throw OidcAuthenticationException::loginFailed();
        }

        // D146 — synchronous, BEFORE the session opens: a failed insert
        // aborts start() entirely (session never opens = no window without
        // a compliance record); the reverse failure (insert ok, login
        // fails below) is harmless because the grant is already burned.
        DB::connection($central)->table('root_account_events')->insert([
            'id' => (string) Str::uuid7(),
            'account_id' => $account->id,
            'event' => 'impersonation.started',
            'actor' => json_encode(['type' => 'support', 'id' => $staffIdentityId, 'grant' => $grantId], JSON_THROW_ON_ERROR),
            'payload' => json_encode(['target_user_id' => $targetUserId, 'expires_at' => (string) $row->expires_at], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $expiresAt = CarbonImmutable::parse($row->expires_at);

        session([
            self::SESSION_GRANT_ID => $grantId,
            self::SESSION_STAFF_ID => $staffIdentityId,
            self::SESSION_TARGET_ID => $targetUserId,
            self::SESSION_EXPIRES_AT => $expiresAt->toIso8601String(),
        ]);

        $this->memoState = new ImpersonationState($grantId, $staffIdentityId, $targetUserId, $expiresAt);
        $this->resolved = true;

        // A burned grant on failed login is the accepted price (research/12
        // §4) — one-time-ness is preserved, the grant is not "un-burned".
        $authenticated = Auth::guard($this->guard())->loginUsingId($targetUserId);

        if ($authenticated === false) {
            throw OidcAuthenticationException::loginFailed();
        }

        event(new ImpersonationStarted($grantId, $staffIdentityId, $targetUserId));
    }

    #[Override]
    public function stop(): void
    {
        $state = $this->state();

        if ($state === null) {
            return;
        }

        /** @var Account|null $account */
        $account = tenancy()->tenant;
        $accountId = $account?->id;

        // D146 — session first: the tenant session, not the audit mirror,
        // is the safety-critical half; a lost `ended` mirror is recoverable
        // from the grant row itself (used_at + expires_at, research/12 §4).
        Auth::guard($this->guard())->logout();
        session()->forget([self::SESSION_GRANT_ID, self::SESSION_STAFF_ID, self::SESSION_TARGET_ID, self::SESSION_EXPIRES_AT]);
        session()->invalidate();
        session()->regenerateToken();

        $this->memoState = null;
        $this->resolved = true;

        if ($accountId !== null) {
            DB::connection($this->centralConnection())->table('root_account_events')->insert([
                'id' => (string) Str::uuid7(),
                'account_id' => $accountId,
                'event' => 'impersonation.ended',
                'actor' => json_encode(['type' => 'support', 'id' => $state->staffIdentityId, 'grant' => $state->grantId], JSON_THROW_ON_ERROR),
                'payload' => json_encode(['target_user_id' => $state->targetUserId], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        event(new ImpersonationEnded($state->grantId, $state->staffIdentityId, $state->targetUserId));
    }

    #[Override]
    public function active(): ?string
    {
        return $this->state()?->grantId;
    }

    #[Override]
    public function state(): ?ImpersonationState
    {
        if ($this->resolved) {
            return $this->memoState;
        }

        $this->resolved = true;

        $grantId = session(self::SESSION_GRANT_ID);
        $staffId = session(self::SESSION_STAFF_ID);
        $targetId = session(self::SESSION_TARGET_ID);
        $expiresAt = session(self::SESSION_EXPIRES_AT);

        if (! is_string($grantId) || ! is_string($staffId) || ! is_string($targetId) || ! is_string($expiresAt)) {
            return $this->memoState = null;
        }

        return $this->memoState = new ImpersonationState($grantId, $staffId, $targetId, CarbonImmutable::parse($expiresAt));
    }

    private function guard(): string
    {
        return (string) config('tenancy.oidc.guard', 'web');
    }

    private function centralConnection(): string
    {
        return (string) config('tenancy.central_connection');
    }
}
