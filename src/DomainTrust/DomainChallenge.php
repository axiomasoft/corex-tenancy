<?php

declare(strict_types=1);

namespace CoreX\Tenancy\DomainTrust;

use DateTimeImmutable;

final readonly class DomainChallenge
{
    public function __construct(
        public string $accountId,
        public NormalizedHost $host,
        public int $generation,
        public DateTimeImmutable $expiresAt,
    ) {}
}
