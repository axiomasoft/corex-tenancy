<?php

declare(strict_types=1);

namespace CoreX\Tenancy;

use CoreX\Exceptions\CentralContextException;
use CoreX\Tenancy\Contracts\TenancyManager;
use CoreX\Tenancy\Contracts\TenantContextResolver;

/**
 * Adapts the cloud {@see TenancyManager} to the P1.12
 * {@see TenantContextResolver} contract (B-11 §3.2): `current()` reads
 * whatever context `StanclTenancyManager::initialize()` last set, throwing
 * when called outside any initialized tenant.
 */
final class TenancyManagerContextResolver implements TenantContextResolver
{
    public function __construct(
        private readonly TenancyManager $manager,
    ) {}

    public function current(): TenantContext
    {
        return $this->manager->context() ?? throw new CentralContextException;
    }
}
