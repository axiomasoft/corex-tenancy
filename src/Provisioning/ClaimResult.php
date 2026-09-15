<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Provisioning;

final readonly class ClaimResult
{
    public function __construct(
        public string $accountId,
        public bool $replayed,
    ) {}
}
