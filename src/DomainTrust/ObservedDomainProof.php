<?php

declare(strict_types=1);

namespace CoreX\Tenancy\DomainTrust;

use SensitiveParameter;

final readonly class ObservedDomainProof
{
    /** @param list<string> $facts */
    public function __construct(
        public array $facts,
        #[SensitiveParameter]
        public ?string $challengeToken = null,
    ) {}
}
