<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Transport;

use CoreX\Tenancy\Central\CentralUnauthorized;
use stdClass;

/** @internal Local RFC9068/RFC8705 claim cross-check; not AS qualification. */
final readonly class AccessTokenValidator
{
    public function __construct(private JwsVerifier $verifier) {}

    public function validate(string $token, ChannelBinding $binding, string $capability, int $now): void
    {
        $p = $this->verifier->verify(compact: $token, type: 'at+jwt', purpose: 'access');

        if (($p['iss'] ?? null) !== $binding->issuer
            || (($p['aud'] ?? null) !== $binding->resource && ($p['aud'] ?? null) !== [$binding->resource])
            || ($p['client_id'] ?? null) !== $binding->clientId || ($p['sub'] ?? null) !== $binding->subject
            || ($p['account_id'] ?? null) !== $binding->accountId || ($p['product'] ?? null) !== $binding->product
            || ! ($p['cnf'] ?? null) instanceof stdClass || get_object_vars($p['cnf']) !== ['x5t#S256' => $binding->certificateThumbprint]
            || ! is_int($p['iat'] ?? null) || ! is_int($p['exp'] ?? null) || $p['iat'] < 0 || $p['exp'] <= $p['iat']
            || $p['exp'] - $p['iat'] > 300 || $p['iat'] > $now + 30 || $p['exp'] <= $now - 30
            || ! is_string($p['jti'] ?? null) || $p['jti'] === '' || ! is_string($p['scope'] ?? null)
            || ! in_array($capability, explode(' ', $p['scope']), true)) {
            throw new CentralUnauthorized('Certificate-bound service claims or capability denied.');
        }

        if (isset($p['nbf']) && (! is_int($p['nbf']) || $p['nbf'] > $now + 30)) {
            throw new CentralUnauthorized('Service token not yet valid.');
        }
    }
}
