<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Oidc;

use CoreX\Tenancy\Exceptions\OidcAuthenticationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * `GET {issuer}/.well-known/openid-configuration` (D123/D129 — endpoints
 * come from Discovery, never hardcoded), cached under a configurable
 * store/TTL so a real login does not round-trip the AS on every request.
 * Uses {@see Http} (not a raw Guzzle/PSR-18 client) so `Http::fake()` works
 * in tests (D130).
 */
final class OidcDiscovery
{
    private const string CACHE_KEY_PREFIX = 'corex_tenancy_oidc_discovery:';

    public function __construct(
        private readonly string $issuer,
        private readonly ?string $cacheStore,
        private readonly int $cacheTtl,
    ) {}

    public function authorizationEndpoint(): string
    {
        return $this->stringField('authorization_endpoint');
    }

    public function tokenEndpoint(): string
    {
        return $this->stringField('token_endpoint');
    }

    public function jwksUri(): string
    {
        return $this->stringField('jwks_uri');
    }

    private function stringField(string $field): string
    {
        $value = $this->document()[$field] ?? null;

        if (! is_string($value) || $value === '') {
            throw OidcAuthenticationException::discoveryFailed();
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        /** @var array<string, mixed> $document */
        $document = Cache::store($this->cacheStore)->remember(
            self::CACHE_KEY_PREFIX.md5($this->issuer),
            $this->cacheTtl,
            function (): array {
                $response = Http::get(rtrim($this->issuer, '/').'/.well-known/openid-configuration');

                if ($response->failed()) {
                    throw OidcAuthenticationException::discoveryFailed();
                }

                /** @var array<string, mixed>|null $json */
                $json = $response->json();

                return $json ?? [];
            },
        );

        return $document;
    }
}
