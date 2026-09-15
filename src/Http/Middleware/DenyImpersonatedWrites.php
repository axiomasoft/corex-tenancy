<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Http\Middleware;

use Closure;
use CoreX\Tenancy\Contracts\ImpersonationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * L1 — HTTP layer of the read-only invariant (D141, research/12 §2): an
 * unsafe method under an active impersonation is denied UNLESS the matched
 * route carries the {@see AllowImpersonatedWrites} marker (by alias or
 * FQCN). Reuses `tenancy.status_gate.read_only_methods` (already the
 * project's one definition of "these HTTP methods are read-only") rather
 * than inventing a second config surface for the same fact (D116/D121).
 *
 * @internal spec: B-11 §5.9, D141
 */
final class DenyImpersonatedWrites
{
    public function __construct(
        private readonly ImpersonationService $impersonation,
    ) {}

    /** @return Response|mixed */
    public function handle(Request $request, Closure $next): mixed
    {
        if ($this->impersonation->state() === null) {
            return $next($request);
        }

        $readOnlyMethods = (array) config('tenancy.status_gate.read_only_methods', ['GET', 'HEAD', 'OPTIONS']);

        if (in_array($request->method(), $readOnlyMethods, true)) {
            return $next($request);
        }

        $middleware = $request->route()?->middleware() ?? [];

        if (in_array('tenancy.impersonation.allow', $middleware, true) || in_array(AllowImpersonatedWrites::class, $middleware, true)) {
            return $next($request);
        }

        abort(403);
    }
}
