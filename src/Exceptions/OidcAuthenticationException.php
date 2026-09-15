<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Exceptions;

use Illuminate\Http\Response;
use RuntimeException;

/**
 * Any OIDC login-flow failure (Discovery, JWKS, token exchange, id_token
 * verification, membership, impersonation gate) — always renders 403, never
 * a partial login (research/10 §3/§7). Messages name only claim/step
 * identifiers, never token content, `client_secret`, or `code_verifier`
 * (Implementation Rule 8).
 */
final class OidcAuthenticationException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function discoveryFailed(): self
    {
        return new self('OIDC discovery document could not be retrieved.');
    }

    public static function jwksFetchFailed(): self
    {
        return new self('OIDC JWKS could not be retrieved.');
    }

    public static function tokenExchangeFailed(): self
    {
        return new self('OIDC token exchange with the authorization server failed.');
    }

    public static function malformedIdToken(): self
    {
        return new self('OIDC id_token is malformed.');
    }

    public static function invalidHeader(string $claim): self
    {
        return new self("OIDC id_token header failed validation: {$claim}.");
    }

    public static function invalidSignature(): self
    {
        return new self('OIDC id_token signature verification failed.');
    }

    public static function claimMismatch(string $claim): self
    {
        return new self("OIDC id_token claim failed validation: {$claim}.");
    }

    public static function invalidState(): self
    {
        return new self('OIDC state/nonce/PKCE session verification failed.');
    }

    public static function membershipDenied(): self
    {
        return new self('OIDC identity is not an active member of this account.');
    }

    public static function impersonationDenied(): self
    {
        return new self('OIDC impersonation is not enabled for this account.');
    }

    public static function loginFailed(): self
    {
        return new self('OIDC login could not be completed for the resolved identity.');
    }

    public function render(): Response
    {
        return response('', 403);
    }
}
