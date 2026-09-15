<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Switcher;

use CoreX\Tenancy\Membership\Internal\Transport\Value;

/** @internal Local, unregistered accounts-menu read profile. */
final readonly class AccountsQuery
{
    public function __construct(public string $identityId, public string $product)
    {
        Value::uuid($identityId);
        Value::product($product);
    }
}
