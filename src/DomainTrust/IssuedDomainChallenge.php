<?php

declare(strict_types=1);

namespace CoreX\Tenancy\DomainTrust;

use SensitiveParameter;

final readonly class IssuedDomainChallenge
{
    public function __construct(
        public DomainChallenge $challenge,
        #[SensitiveParameter]
        public string $token,
    ) {}
}
