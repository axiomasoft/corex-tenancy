<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Events;

use CoreX\Tenancy\DomainTrust\DomainTrustTransition;

final class DomainTrustChanged
{
    public function __construct(public readonly DomainTrustTransition $transition) {}
}
