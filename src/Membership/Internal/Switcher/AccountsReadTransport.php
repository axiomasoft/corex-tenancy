<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Switcher;

/** @internal Composition seam for an eventual central accounts transport. */
interface AccountsReadTransport
{
    public function accountsFor(AccountsQuery $query): AccountsResult;
}
