<?php

declare(strict_types=1);

namespace CoreX\Tenancy\DomainTrust;

final readonly class DomainTrustActor
{
    public function __construct(public string $id) {}
}
