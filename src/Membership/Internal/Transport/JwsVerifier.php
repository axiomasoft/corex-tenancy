<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Transport;

use CoreX\Tenancy\Central\CentralUnauthorized;

/** @internal Injected trusted keys only: never retrieves message-directed keys. */
final readonly class JwsVerifier
{
    /** @param array<string, array{pem: string, purpose: string}> $keys */
    public function __construct(private array $keys) {}

    /** @return array<string, mixed> */
    public function verify(string $compact, string $type, string $purpose): array
    {
        if (strlen($compact) > 65536) {
            throw new CentralUnauthorized('JWS limit exceeded.');
        }
        $parts = explode('.', $compact);

        if (count($parts) !== 3) {
            throw new CentralUnauthorized('Invalid compact JWS.');
        }
        $header = StrictJson::object(self::decode($parts[0]));
        Value::fields($header, ['alg', 'typ', 'kid']);
        $kid = $header['kid'];

        if ($header['alg'] !== 'RS256' || $header['typ'] !== $type || ! is_string($kid)
            || ! isset($this->keys[$kid]) || $this->keys[$kid]['purpose'] !== $purpose) {
            throw new CentralUnauthorized('Untrusted JWS algorithm, type or key purpose.');
        }
        $key = openssl_pkey_get_public($this->keys[$kid]['pem']);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        if ($key === false || $details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048
            || openssl_verify($parts[0].'.'.$parts[1], self::decode($parts[2]), $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new CentralUnauthorized('Invalid JWS signature.');
        }

        return StrictJson::object(self::decode($parts[1]));
    }

    private static function decode(string $value): string
    {
        if ($value === '' || preg_match('/^[a-zA-Z0-9_-]+$/D', $value) !== 1 || strlen($value) % 4 === 1) {
            throw new CentralUnauthorized('Invalid JWS base64url.');
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $value) {
            throw new CentralUnauthorized('Noncanonical JWS base64url.');
        }

        return $decoded;
    }
}
