<?php

declare(strict_types=1);

namespace CoreX\Tenancy;

use Closure;
use CoreX\Tenancy\Contracts\TenancyManager;
use CoreX\Tenancy\Models\Account;
use RuntimeException;
use Stancl\Tenancy\Database\Models\Tenant as StanclTenant;
use Stancl\Tenancy\Tenancy as Stancl;

/**
 * Cloud {@see TenancyManager}: delegates account-level connection/cache/
 * queue/fs switching to stancl's own `Tenancy::initialize/end/run` (D13 —
 * `Stancl\*` imports stay inside this package). Workspace (P2.6, a logical
 * tier stancl has no concept of) is tracked here alongside the stancl
 * tenant, not inside it.
 */
final class StanclTenancyManager implements TenancyManager
{
    private ?WorkspaceRef $workspace = null;

    public function __construct(
        private readonly Stancl $tenancy,
    ) {}

    public function context(): ?TenantContext
    {
        if (! $this->tenancy->initialized) {
            return null;
        }

        return $this->buildContext();
    }

    public function initialized(): bool
    {
        return $this->tenancy->initialized;
    }

    public function initialize(AccountRef $account): void
    {
        $this->tenancy->initialize($account->id);
        $this->workspace = null;
    }

    public function setWorkspace(?WorkspaceRef $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function end(): void
    {
        $this->tenancy->end();
        $this->workspace = null;
    }

    public function runFor(AccountRef $account, ?WorkspaceRef $workspace, Closure $callback): mixed
    {
        $tenant = Stancl::find($account->id);

        if ($tenant === null) {
            throw new RuntimeException("Account [{$account->id}] not found — cannot run in its tenant context.");
        }

        $previousWorkspace = $this->workspace;

        try {
            return $this->tenancy->run($tenant, function () use ($workspace, $callback): mixed {
                $this->workspace = $workspace;

                return $callback();
            });
        } finally {
            // Stancl restores the tenant (and runs bootstrapper reverts) after the callback.
            $this->workspace = $previousWorkspace;
        }
    }

    private function buildContext(): TenantContext
    {
        /** @var StanclTenant&Account $tenant */
        $tenant = $this->tenancy->tenant;

        $account = new AccountRef(
            id: (string) $tenant->getTenantKey(),
            slug: (string) $tenant->slug,
            status: (string) $tenant->status,
            features: [],
            limits: [],
        );

        return new TenantContext($account, $this->workspace);
    }
}
