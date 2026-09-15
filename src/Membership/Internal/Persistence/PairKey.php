<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Persistence;

use CoreX\Tenancy\Membership\Internal\Transport\Value;

/** @internal Test-only reference key; it is not a runtime tenant binding. */
final readonly class PairKey
{
    public function __construct(
        public string $accountId,
        public string $identityId,
    ) {
        Value::uuid($accountId);
        Value::uuid($identityId);
    }
}
