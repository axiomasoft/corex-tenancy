<?php

declare(strict_types=1);

namespace CoreX\Tenancy\DomainTrust;

final readonly class ChallengeConsumption
{
    public function __construct(public bool $consumed) {}
}
