<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Provisioning;

final readonly class QualifiedTemplate
{
    public function __construct(
        public string $clusterId,
        public string $templateId,
        public int $templateVersion,
        public string $artifactDigest,
    ) {}
}
