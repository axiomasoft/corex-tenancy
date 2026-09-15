<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Exceptions;

use CoreX\Tenancy\Resolvers\HostTenantResolver;
use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;

/**
 * Thrown by {@see HostTenantResolver} — extends the
 * stancl base class (not an interface) so {@see
 * \Stancl\Tenancy\Middleware\IdentificationMiddleware::initializeTenancy()}
 * catches it in its `$onFail` handler.
 */
final class TenantCouldNotBeIdentifiedByHostException extends TenantCouldNotBeIdentifiedException
{
    public function __construct(string $host)
    {
        $this->tenantCouldNotBeIdentified("by host {$host}");
    }
}
