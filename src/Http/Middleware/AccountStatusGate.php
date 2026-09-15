<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Http\Middleware;

use Closure;
use CoreX\Tenancy\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * B-11 §5.1 access rules, read from the already-hydrated tenant model — no
 * root-DB round trip here. Fail-closed: `match` carries no default-pass
 * branch, an unknown status (including `pending`/`provisioning`, which
 * should never reach this middleware — cache/domain already reject them)
 * falls through to 403.
 */
final class AccountStatusGate
{
    /** @return Response|mixed */
    public function handle(Request $request, Closure $next): mixed
    {
        /** @var Account $account */
        $account = tenancy()->tenant;

        return match ($account->status) {
            'trial', 'active' => $next($request),
            'suspended' => $this->ownerOnRoute($request, $next, $account, 'payment_route'),
            'grace' => $this->graceGate($request, $next, $account),
            'exporting', 'deleted' => abort(410),
            default => abort(403),
        };
    }

    /** @return Response|mixed */
    private function ownerOnRoute(Request $request, Closure $next, Account $account, string $routeConfigKey): mixed
    {
        if (! $this->isOwner($account) || ! $this->matchesConfiguredRoute($request, config("tenancy.status_gate.{$routeConfigKey}"))) {
            abort(403);
        }

        return $next($request);
    }

    /** @return Response|mixed */
    private function graceGate(Request $request, Closure $next, Account $account): mixed
    {
        if (! $this->isOwner($account)) {
            abort(403);
        }

        $readOnly = in_array($request->method(), (array) config('tenancy.status_gate.read_only_methods', []), true);
        $onExportRoute = $this->matchesConfiguredRoute($request, config('tenancy.status_gate.export_route'));

        if (! $readOnly && ! $onExportRoute) {
            abort(403);
        }

        return $next($request);
    }

    private function isOwner(Account $account): bool
    {
        $userId = auth()->id();

        return $userId !== null && $account->owner_identity_id === $userId;
    }

    private function matchesConfiguredRoute(Request $request, mixed $routeConfig): bool
    {
        if (! is_string($routeConfig) || $routeConfig === '') {
            return false;
        }

        if ($request->route()?->getName() === $routeConfig) {
            return true;
        }

        return $request->is(ltrim($routeConfig, '/'));
    }
}
