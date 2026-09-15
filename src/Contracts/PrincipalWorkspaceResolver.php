<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Contracts;

use CoreX\Tenancy\WorkspaceRef;

/** Membership lookup on the already initialized account connection; no authentication or connection switching. */
interface PrincipalWorkspaceResolver
{
    /** A null slug selects the principal's active default membership. */
    public function resolveFor(string|int $principalId, ?string $slug): WorkspaceRef;
}
