<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Contracts;

use CoreX\Tenancy\DomainTrust\NormalizedHost;
use CoreX\Tenancy\DomainTrust\ObservedDomainProof;

interface DomainTrustPolicy
{
    public function normalize(string $host): NormalizedHost;

    public function accepts(NormalizedHost $host, ObservedDomainProof $proof): bool;
}
