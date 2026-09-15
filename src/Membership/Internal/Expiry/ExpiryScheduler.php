<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Expiry;

use CoreX\Tenancy\Membership\Internal\Persistence\PairKey;
use CoreX\Tenancy\Membership\Internal\Persistence\PairState;
use CoreX\Tenancy\Membership\Internal\Persistence\PairStore;

/** @internal Fixture-only priority expiry; no worker or kill mechanism is registered. */
final readonly class ExpiryScheduler
{
    public function __construct(private PairStore $pairs) {}

    public function expire(PairKey $key, string $barrierId): PairState
    {
        return $this->pairs->invalidate(key: $key, barrierId: $barrierId);
    }
}
