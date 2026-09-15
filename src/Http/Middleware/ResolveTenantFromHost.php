<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Http\Middleware;

use Closure;
use CoreX\Tenancy\Resolvers\HostTenantResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stancl\Tenancy\Middleware\IdentificationMiddleware;
use Stancl\Tenancy\Tenancy;

/**
 * Kernel early-identification (B-11 §5.4 п.1–2). Host is read ONLY from
 * {@see Request::getHost()} — the edge (Caddy) is not a source of truth,
 * client-supplied `X-Account-*`/`X-Forwarded-Host` are never consulted.
 */
final class ResolveTenantFromHost extends IdentificationMiddleware
{
    public function __construct(
        protected Tenancy $tenancy,
        protected HostTenantResolver $resolver,
    ) {}

    /** @return Response|mixed */
    public function handle(Request $request, Closure $next): mixed
    {
        return $this->initializeTenancy($request, $next, $request->getHost());
    }
}
