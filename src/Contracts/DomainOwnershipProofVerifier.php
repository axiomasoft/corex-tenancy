<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Contracts;

use CoreX\Tenancy\DomainTrust\NormalizedHost;
use CoreX\Tenancy\DomainTrust\ObservedDomainProof;

interface DomainOwnershipProofVerifier
{
    public function observe(NormalizedHost $host): ObservedDomainProof;
}
