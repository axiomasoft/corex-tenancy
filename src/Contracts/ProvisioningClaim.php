<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Contracts;

use CoreX\Tenancy\Provisioning\ClaimRequest;
use CoreX\Tenancy\Provisioning\ClaimResult;

interface ProvisioningClaim
{
    public function claim(ClaimRequest $request): ClaimResult;
}
