<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Contracts;

use CoreX\Tenancy\DomainTrust\DomainTrustActor;
use CoreX\Tenancy\DomainTrust\DomainTrustOperation;
use CoreX\Tenancy\DomainTrust\NormalizedHost;

interface DomainTrustAuthorizer
{
    public function assertAllowed(
        DomainTrustActor $actor,
        DomainTrustOperation $operation,
        string $accountId,
        NormalizedHost $host,
    ): void;
}
