<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Http\Controllers;

use Carbon\CarbonImmutable;
use CoreX\Tenancy\Contracts\ImpersonationService;
use CoreX\Tenancy\Exceptions\OidcAuthenticationException;
use CoreX\Tenancy\Identity\IdentitySyncer;
use CoreX\Tenancy\Models\Account;
use CoreX\Tenancy\Oidc\IdTokenVerifier;
use CoreX\Tenancy\Oidc\OidcClient;
use CoreX\Tenancy\Oidc\OidcDiscovery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Authorization-code + PKCE RP endpoints (AC-14). `callback()` runs the
 * checks EXACTLY in the order of research/10 §3 / Implementation Rule 5,
 * extended by D145 (P2.11) for the `imp` branch: one-time `state` → code
 * exchange → id_token header/signature/`iss`/`aud`/`nonce`/`sub` (all inside
 * {@see IdTokenVerifier}) → `acct` === current tenant (BOTH lanes) → `imp`
 * claim: present ⇒ {@see ImpersonationService::start()} (membership NOT
 * checked — support is not a member, D145) BEFORE `IdentitySyncer` ever
 * runs; absent ⇒ `root_identity_accounts.status === 'active'` (RIGID
 * equality — the enum is three-valued, `invited` must NOT pass) →
 * {@see IdentitySyncer::syncToAccount()}. Both lanes converge on
 * `session()->regenerate()` → `Auth::loginUsingId()` (the ONLY seam that
 * constructs the Principal — never a class from `corex/auth`, D125) →
 * `_corex_auth_at` marker (AC-15, read by `EnforceSessionLifetime`). Any
 * failure is an {@see OidcAuthenticationException} — no partial login. The
 * ordinary (non-`imp`) lane is UNCHANGED from P2.22 — its own Validation
 * suite must stay green without edits (D145).
 */
final class OidcLoginController
{
    public function __construct(
        private readonly OidcDiscovery $discovery,
        private readonly OidcClient $client,
        private readonly IdTokenVerifier $verifier,
        private readonly IdentitySyncer $syncer,
        private readonly ImpersonationService $impersonation,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        return redirect()->away($this->client->authorizationUrl($request));
    }

    public function callback(Request $request): RedirectResponse
    {
        $codeVerifier = $this->consumeState($request);
        $nonce = $this->consumeNonce($request);

        $tokens = $this->client->exchangeCodeForTokens(
            code: $this->requireQueryString($request, 'code'),
            codeVerifier: $codeVerifier,
        );

        $claims = $this->verifier->verify(
            idToken: $tokens['id_token'],
            jwksUri: $this->discovery->jwksUri(),
            nonce: $nonce,
        );

        $sub = $claims['sub'];

        // IdTokenVerifier::verify() already guarantees a non-empty string
        // sub — this narrows the type for PHPStan without re-trusting input.
        if (! is_string($sub)) {
            throw OidcAuthenticationException::claimMismatch('sub');
        }

        /** @var Account $account */
        $account = tenancy()->tenant;

        $this->assertAccountClaim($claims, $account);

        $grantId = $this->impersonationClaim($claims);

        if ($grantId !== null) {
            // D145 — support is NOT a member of this account: membership is
            // NOT checked (authorization is the grant itself) and
            // IdentitySyncer is NOT called (it would project a staff
            // identity into the client's own `users` table).
            $request->session()->regenerate();

            $this->impersonation->start($grantId, $sub);

            $request->session()->put('_corex_auth_at', CarbonImmutable::now()->toIso8601String());

            return redirect()->intended('/');
        }

        $this->assertMembership($sub, $account);

        $this->syncer->syncToAccount($sub, $account->id);

        $request->session()->regenerate();

        $authenticated = Auth::guard($this->guard())->loginUsingId($sub);

        // loginUsingId() returns `false` (WITHOUT throwing) when the
        // configured provider's retrieveById($sub) resolves to null — e.g.
        // IdentitySyncer::syncToAccount() projected a row the provider then
        // refuses to surface (inactive/unavailable). Continuing past this
        // would silently redirect an unauthenticated request — the exact
        // partial login this docblock forbids.
        if ($authenticated === false) {
            throw OidcAuthenticationException::loginFailed();
        }

        $request->session()->put('_corex_auth_at', CarbonImmutable::now()->toIso8601String());

        return redirect()->intended('/');
    }

    /**
     * Reads `state` from the session and forgets the key IMMEDIATELY
     * (Implementation Rule 5) before even comparing it — a repeated
     * callback with the same query string can never replay it. Returns the
     * `code_verifier` consumed in the same pass (also one-time).
     */
    private function consumeState(Request $request): string
    {
        // pull() reads AND removes the key in one call — the one-time
        // secret is gone from the session the instant it is read, before
        // it is even compared (Implementation Rule 5).
        $sessionState = $request->session()->pull(OidcClient::SESSION_STATE_KEY);

        $queryState = $request->query('state');

        if (
            ! is_string($sessionState) || $sessionState === ''
            || ! is_string($queryState) || $queryState === ''
            || ! hash_equals($sessionState, $queryState)
        ) {
            throw OidcAuthenticationException::invalidState();
        }

        $codeVerifier = $request->session()->pull(OidcClient::SESSION_CODE_VERIFIER_KEY);

        if (! is_string($codeVerifier) || $codeVerifier === '') {
            throw OidcAuthenticationException::invalidState();
        }

        return $codeVerifier;
    }

    private function consumeNonce(Request $request): string
    {
        $nonce = $request->session()->pull(OidcClient::SESSION_NONCE_KEY);

        if (! is_string($nonce) || $nonce === '') {
            throw OidcAuthenticationException::invalidState();
        }

        return $nonce;
    }

    private function requireQueryString(Request $request, string $key): string
    {
        $value = $request->query($key);

        if (! is_string($value) || $value === '') {
            throw OidcAuthenticationException::invalidState();
        }

        return $value;
    }

    /**
     * `acct` sanity check — BOTH lanes (D145): impersonation is authorized
     * by the grant, not by membership, but the id_token still names the
     * account it was issued for.
     *
     * @param  array<string, mixed>  $claims
     */
    private function assertAccountClaim(array $claims, Account $account): void
    {
        $acct = $claims['acct'] ?? null;

        if (! is_string($acct) || $acct !== $account->id) {
            throw OidcAuthenticationException::membershipDenied();
        }
    }

    /**
     * Ordinary (non-`imp`) lane only (D145) — support is never a member of
     * the client's account, so this check must not run on the impersonation
     * branch.
     */
    private function assertMembership(string $sub, Account $account): void
    {
        $membership = DB::connection($this->centralConnection())
            ->table('root_identity_accounts')
            ->where('identity_id', $sub)
            ->where('account_id', $account->id)
            ->first();

        // RIGID equality (Implementation Rule 5) — the enum is three-valued
        // (invited/active/blocked); `<> 'blocked'` would let an
        // un-onboarded `invited` identity in.
        if ($membership === null || $membership->status !== 'active') {
            throw OidcAuthenticationException::membershipDenied();
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return non-empty-string|null grant id, or null outside impersonation
     */
    private function impersonationClaim(array $claims): ?string
    {
        $imp = $claims['imp'] ?? null;
        $isImpersonating = $imp !== null && $imp !== '';

        if (! $isImpersonating) {
            return null;
        }

        // Fail-closed gate kept from P2.22 (D145 does not change this
        // outcome, only what runs AFTER it): an `imp` claim while the
        // feature is off is rejected, never silently accepted.
        if (! (bool) config('tenancy.oidc.impersonation.enabled')) {
            throw OidcAuthenticationException::impersonationDenied();
        }

        if (! is_string($imp)) {
            throw OidcAuthenticationException::claimMismatch('imp');
        }

        return $imp;
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
