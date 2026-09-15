<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Oidc;

use CoreX\Tenancy\Exceptions\OidcAuthenticationException;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * JWKS fetch/cache WITHOUT `Firebase\JWT\CachedKeySet` (D130 — no PSR-6 in
 * this tree, and a raw PSR-18 client would not be `Http::fake()`-able).
 * `Http::get($jwksUri)` → `JWK::parseKeySet()` → `Cache::store()->put()`.
 * On an unknown `kid` (AS rotated its signing key), exactly ONE forced
 * refetch runs, gated by a separate cooldown cache key — otherwise an
 * attacker-controlled `kid` becomes a JWKS-endpoint DoS lever.
 */
final class JwksKeySet
{
    private const string CACHE_KEY_PREFIX = 'corex_tenancy_oidc_jwks:';

    private const string COOLDOWN_KEY_PREFIX = 'corex_tenancy_oidc_jwks_refetch_cooldown:';

    public function __construct(
        private readonly ?string $cacheStore,
        private readonly int $cacheTtl,
        private readonly int $refetchCooldownSeconds,
    ) {}

    /**
     * @return array<string, Key>
     */
    public function keysForVerification(string $jwksUri, string $kid): array
    {
        $keys = JWK::parseKeySet($this->cachedJwks($jwksUri));

        if (array_key_exists($kid, $keys)) {
            return $keys;
        }

        return JWK::parseKeySet($this->forceRefetch($jwksUri));
    }

    /**
     * @return array<string, mixed>
     */
    private function cachedJwks(string $jwksUri): array
    {
        /** @var array<string, mixed> $jwks */
        $jwks = Cache::store($this->cacheStore)->remember(
            self::CACHE_KEY_PREFIX.md5($jwksUri),
            $this->cacheTtl,
            fn (): array => $this->fetch($jwksUri),
        );

        return $jwks;
    }

    /**
     * @return array<string, mixed>
     */
    private function forceRefetch(string $jwksUri): array
    {
        $store = Cache::store($this->cacheStore);
        $cooldownKey = self::COOLDOWN_KEY_PREFIX.md5($jwksUri);

        // Cooldown active: someone already forced a refetch for this JWKS
        // very recently and the kid is STILL unknown — the cached (already
        // fresh) copy is the answer, not another AS round-trip.
        if ($store->has($cooldownKey)) {
            return $this->cachedJwks($jwksUri);
        }

        $store->put($cooldownKey, true, $this->refetchCooldownSeconds);

        $jwks = $this->fetch($jwksUri);
        $store->put(self::CACHE_KEY_PREFIX.md5($jwksUri), $jwks, $this->cacheTtl);

        return $jwks;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $jwksUri): array
    {
        $response = Http::get($jwksUri);

        if ($response->failed()) {
            throw OidcAuthenticationException::jwksFetchFailed();
        }

        /** @var array<string, mixed>|null $json */
        $json = $response->json();

        return $json ?? [];
    }
}
