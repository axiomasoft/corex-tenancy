<?php

declare(strict_types=1);

namespace CoreX\Tenancy\DomainTrust;

enum DomainTrustChangeReason: string
{
    case Verified = 'verified';
    case Revoked = 'revoked';
    case Reassigned = 'reassigned';
}
