<?php

declare(strict_types=1);

namespace CoreX\Tenancy\DomainTrust;

final readonly class DomainChallengeBinding
{
    public function __construct(
        public string $accountId,
        public NormalizedHost $host,
        public int $generation,
    ) {}
}
