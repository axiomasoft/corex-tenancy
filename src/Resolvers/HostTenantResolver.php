<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Resolvers;

use CoreX\Tenancy\DomainTrust\NormalizedHost;
use CoreX\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByHostException;
use CoreX\Tenancy\Models\Account;
use CoreX\Tenancy\Models\Domain;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Override;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Resolvers\Contracts\CachedTenantResolver;

/**
 * Resolves `Request::getHost()` to an {@see Account} through `root_domains`
 * (D119 — no bespoke cache class: the base class already owns cache
 * keys/TTL/store/invalidation, this class only supplies the source of
 * truth). §7.3 п.9 double guard: a pending-shell account has neither a
 * `root_domains` row nor a `slug`, so it is unreachable by host even before
 * the `slug IS NOT NULL` check below runs.
 */
final class HostTenantResolver extends CachedTenantResolver
{
    #[Override]
    public function resolveWithoutCache(mixed ...$args): Tenant
    {
        $host = self::normalizeHost((string) $args[0]);
        $centralConnection = (string) config('tenancy.central_connection');

        $domain = Domain::on(connection: $centralConnection)
            ->whereRaw('lower(host) = ?', [$host])
            ->whereNull('deleted_at')
            ->whereNotNull('verified_at')
            ->first();

        if ($domain === null) {
            throw new TenantCouldNotBeIdentifiedByHostException($host);
        }

        /** @var (Tenant&Model)|null $account */
        $account = Account::on(connection: $centralConnection)
            ->whereKey($domain->account_id)
            ->whereNull('deleted_at')
            ->whereNotNull('slug')
            ->first();

        if ($account === null) {
            throw new TenantCouldNotBeIdentifiedByHostException($host);
        }

        return $account;
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function getPossibleCacheKeys(Tenant&Model $tenant): array
    {
        $tenant->unsetRelation('domains');

        /** @var Account $tenant */
        return $tenant->domains
            ->map(fn (Domain $domain): string => $this->formatCacheKey(self::normalizeHost($domain->host)))
            ->all();
    }

    public static function normalizeHost(string $host): string
    {
        try {
            return (new NormalizedHost($host))->value;
        } catch (InvalidArgumentException) {
            throw new TenantCouldNotBeIdentifiedByHostException($host);
        }
    }
}
