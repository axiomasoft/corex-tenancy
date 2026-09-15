<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Contracts;

use CoreX\Tenancy\Provisioning\ClaimRequest;
use CoreX\Tenancy\Provisioning\QualifiedTemplate;

interface TemplateIntegrityVerifier
{
    public function qualify(ClaimRequest $request): QualifiedTemplate;
}
