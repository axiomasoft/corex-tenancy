<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Switcher;

use Closure;
use CoreX\Tenancy\Central\CentralProtocolViolation;
use CoreX\Tenancy\Central\CentralUnavailable;
use CoreX\Tenancy\Membership\Internal\Transport\StrictJson;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

/** @internal Explicitly composed display cache; it is never an authority source. */
final readonly class RedisAccountMenuCache implements AccountMenuCache
{
    /** @param Closure(AccountsQuery): ?string $versionReader */
    public function __construct(
        private Repository $cache,
        private Closure $versionReader,
        private string $namespace,
    ) {
        if (trim($namespace) === '') {
            throw new CentralProtocolViolation('Account-menu cache namespace is required.');
        }
    }

    public function versionFor(AccountsQuery $query): string
    {
        try {
            $version = ($this->versionReader)($query);

            if (! is_string($version) || trim($version) === '') {
                throw new CentralUnavailable('Authoritative account-list version is unavailable.');
            }

            return $version;
        } catch (CentralUnavailable $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new CentralUnavailable('Authoritative account-list version is unavailable.', previous: $exception);
        }
    }

    public function resultFor(AccountsQuery $query, string $membershipVersion): ?AccountsResult
    {
        try {
            $payload = $this->cache->get($this->key(query: $query, membershipVersion: $membershipVersion));

            return is_string($payload) ? $this->decode(payload: $payload, query: $query, membershipVersion: $membershipVersion) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function put(AccountsQuery $query, AccountsResult $result, int $ttlSeconds): void
    {
        if (! $result->isFor(query: $query) || $ttlSeconds < 1 || $ttlSeconds > AccountMenuReader::CACHE_TTL_SECONDS) {
            throw new CentralProtocolViolation('Invalid account-menu cache write.');
        }

        try {
            $this->cache->put(
                key: $this->key(query: $query, membershipVersion: $result->membershipVersion),
                value: json_encode($this->encode(result: $result), JSON_THROW_ON_ERROR),
                ttl: $ttlSeconds,
            );
        } catch (Throwable) {
            // A display-cache write is optional. The caller already has the
            // authoritative response and must not turn this into a grant.
        }
    }

    private function key(AccountsQuery $query, string $membershipVersion): string
    {
        return rtrim($this->namespace, ':').':'.hash('sha256', json_encode([
            'identity_id' => $query->identityId,
            'product' => $query->product,
            'membership_version' => $membershipVersion,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function encode(AccountsResult $result): array
    {
        return [
            'identity_id' => $result->identityId,
            'product' => $result->product,
            'membership_version' => $result->membershipVersion,
            'observed_at' => $result->observedAt->format('Y-m-d\\TH:i:s.u\\Z'),
            'accounts' => array_map(static fn (AccountListEntry $account): array => [
                'account_id' => $account->accountId,
                'name' => $account->name,
                'slug' => $account->slug,
                'primary_host' => $account->primaryHost,
                'status' => $account->status,
            ], $result->accounts),
        ];
    }

    private function decode(string $payload, AccountsQuery $query, string $membershipVersion): ?AccountsResult
    {
        $decoded = StrictJson::object($payload);
        $keys = array_keys($decoded);
        sort($keys);

        if ($keys !== ['accounts', 'identity_id', 'membership_version', 'observed_at', 'product']
            || $decoded['identity_id'] !== $query->identityId
            || $decoded['product'] !== $query->product
            || $decoded['membership_version'] !== $membershipVersion
            || ! is_string($decoded['observed_at'])
            || ! is_array($decoded['accounts'])) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $decoded['observed_at']) !== 1) {
            return null;
        }

        try {
            $accounts = array_map(function (mixed $account): AccountListEntry {
                if (! is_object($account)) {
                    throw new CentralProtocolViolation('Invalid cached account entry.');
                }
                $entry = get_object_vars($account);
                $keys = array_keys($entry);
                sort($keys);

                if ($keys !== ['account_id', 'name', 'primary_host', 'slug', 'status']
                    || ! is_string($entry['account_id'])
                    || ! is_string($entry['name'])
                    || ! is_string($entry['slug'])
                    || ! is_string($entry['primary_host'])
                    || ! is_string($entry['status'])) {
                    throw new CentralProtocolViolation('Invalid cached account entry.');
                }

                return new AccountListEntry(
                    accountId: $entry['account_id'],
                    name: $entry['name'],
                    slug: $entry['slug'],
                    primaryHost: $entry['primary_host'],
                    status: $entry['status'],
                );
            }, $decoded['accounts']);

            $result = new AccountsResult(
                identityId: $decoded['identity_id'],
                product: $decoded['product'],
                membershipVersion: $decoded['membership_version'],
                accounts: $accounts,
                observedAt: (new DateTimeImmutable($decoded['observed_at']))->setTimezone(new DateTimeZone('+00:00')),
            );

            return $result->isFor(query: $query) && $result->membershipVersion === $membershipVersion ? $result : null;
        } catch (Throwable) {
            return null;
        }
    }
}
