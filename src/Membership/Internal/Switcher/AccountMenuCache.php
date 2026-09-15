<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Switcher;

/** @internal Local cache seam; it neither reads nor writes a central cache. */
interface AccountMenuCache
{
    public function versionFor(AccountsQuery $query): ?string;

    public function resultFor(AccountsQuery $query, string $membershipVersion): ?AccountsResult;

    public function put(AccountsQuery $query, AccountsResult $result, int $ttlSeconds): void;
}
